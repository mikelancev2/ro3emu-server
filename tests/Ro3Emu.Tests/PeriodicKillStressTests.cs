using Microsoft.AspNetCore.Builder;
using Microsoft.Data.Sqlite;
using Microsoft.Extensions.DependencyInjection;
using Romsg;
using Ro3Emu.Bot;
using Ro3Emu.Server;
using Ro3Emu.Server.Accounts;
using Ro3Emu.Server.Game;
using Ro3Emu.Server.Maps;

namespace Ro3Emu.Tests;

/// <summary>
/// Reproduces the production lockup (2026-11-08, found live with a dotnet-dump): an auto-battle role with Poisoned
/// and Bleeding both ticking left Character.StateLock stuck at 0 permits indefinitely. Many near-simultaneous kills
/// (CombatService -> RewardService.CommitKillAsync, each taking CharacterService.Lock) racing against the periodic
/// damage ticks (BuffService.TickPeriodic -> CombatService.PeriodicPlayerDamageAsync, also taking it) is the closest
/// match found by code reading; this stresses exactly that combination to see whether it actually deadlocks.
/// GmUnlimit's invincibility keeps the role itself alive against its own Poisoned/Bleeding ticks (CommitPlayerDamageAsync
/// zeroes any incoming damage for it): without it those two debuffs killed a fresh level-1 role in one tick, which
/// made every later cast refuse as SkillBadState and looked exactly like the lock being stuck.
/// </summary>
public sealed class PeriodicKillStressTests : IAsyncLifetime
{
    private readonly string _directory = Path.Combine(RepoRoot(), ".loadtest-temp", "periodic-kill-" + Guid.NewGuid().ToString("N"));
    private WebApplication _app = null!;
    private string _http = "";
    private T Service<T>() where T : notnull => _app.Services.GetRequiredService<T>();
    private GameSession Session => Service<SessionRegistry>().All().Single(s => s.Account == "stress" && s.Role != null);

    public async Task InitializeAsync()
    {
        var (web, micro, game) = TestPorts.FreePorts();
        _app = ServerHost.Create([], o =>
        {
            o.DataDir = _directory; o.GameDataDir = Path.Combine(RepoRoot(), "data");
            o.HttpPort = web; o.MicroGatePort = micro; o.GameGatePort = game;
        });
        SureHitRolls.Install(_app);
        await _app.StartAsync(); _http = $"http://127.0.0.1:{web}";
    }

    public async Task DisposeAsync()
    {
        await _app.StopAsync(); await _app.DisposeAsync(); SqliteConnection.ClearAllPools();
        try { Directory.Delete(_directory, true); } catch (IOException) { }
    }

    private async Task<BotClient> Enter()
    {
        var bot = new BotClient(_http, File.ReadAllText(Path.Combine(_directory, "gate_rsa_public.pem")));
        await bot.LoginAsync("stress", "pw"); await bot.EnterGateAsync();
        long role = (await bot.CreateRoleAsync("Stress")).RoleBaseInfo.Id;
        await bot.EnterWorldAsync(role);
        await bot.Inbox.WaitAsync(e => e.IsGame(ShopService.ShopSync), TimeSpan.FromSeconds(10));
        return bot;
    }

    private static string RepoRoot()
    {
        for (var dir = new DirectoryInfo(AppContext.BaseDirectory); dir != null; dir = dir.Parent)
            if (File.Exists(Path.Combine(dir.FullName, "Ro3Emu.slnx"))) return dir.FullName;
        throw new InvalidOperationException("repo root not found");
    }

    [ClientDataFact]
    public async Task ManyConcurrentKillsWithTwoPeriodicDebuffsNeverLeavesTheLockStuck()
    {
        await using var bot = await Enter();
        var player = Session;
        const int Poisoned = 903001, Bleeding = 902901;
        var monsters = await Service<GameLoop>().InvokeAsync<List<MonsterUnit>>(async () =>
        {
            player.GmCombat |= GmCombatSwitches.Unlimited; // survive its own Poisoned/Bleeding ticks; see class doc
            await Service<BuffService>().AddAsync(player, Poisoned, 30000, player.Role!.Id);
            await Service<BuffService>().AddAsync(player, Bleeding, 30000, player.Role!.Id);
            var service = Service<MonsterService>();
            var list = new List<MonsterUnit>();
            for (int i = 0; i < 20; i++)
            {
                var target = await service.SpawnSingleAsync(player.SceneEntry, 5000001,
                    new MapPoint(player.Position.X, player.Position.Y, player.Position.Z, 0), player.InstanceId,
                    customTemplate: service.Template(5000001)! with { RoamDistance = 0 });
                target!.Hp = 1;
                list.Add(target);
            }
            return list;
        });

        // A normal attack cast at a new target cancels the swing in flight (CancelCastAsync) rather than running
        // alongside it, and CastCoreAsync's "turn" carries the cancelled swing's remaining time into the next one's
        // lead: targeting 20 monsters back-to-back queues 20 swings end to end, so only the last one ever reaches
        // its impact point -- the first 19 are superseded while still pending and never land a hit. That queue is
        // still the stress this test wants: each request's admission (cast validation, cost, cooldown, the previous
        // swing's CancelCastAsync) takes CharacterService.Lock once, back-to-back, while the periodic ticks race for
        // the same lock on their own timer. CastSkillRsp is sent the moment a request is admitted, independent of
        // its swing's lead time, so waiting for it after every send is what actually proves the lock never got
        // stuck; only the final, uncancelled swing is expected to land a real kill.
        foreach (var monster in monsters)
        {
            var ack = bot.Inbox.WaitAsync(e => e.IsGame(CombatService.UseSkill), TimeSpan.FromSeconds(10));
            await bot.SendGameAsync(CombatService.UseSkill, new CastSkillReq { SkillId = 1000001, TargetId = monster.Uid });
            Assert.Equal(0, CastSkillRsp.Parser.ParseFrom((await ack).Body).Result);
            await Task.Delay(20); // close enough together to overlap, not so fast the client-side swing gate refuses them
        }

        var lastMonster = monsters[^1];
        var finalHit = bot.Inbox.WaitAsync(e => e.IsGame(CombatService.SkillHit)
            && SkillHitInfo.Parser.ParseFrom(e.Body).Dsts.Any(d => d.DstID == lastMonster.Uid && d.IsDead), TimeSpan.FromSeconds(30));
        await finalHit; // a timeout here is the repro: something is still holding the lock
    }
}
