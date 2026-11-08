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

        // Fire every kill back-to-back, not waiting for each one's own confirmation: this is what lets many
        // CommitKillAsync calls (and the periodic ticks landing in the same window) race for the same lock.
        var sent = new List<(long Uid, Task<BotEvent> Hit)>();
        foreach (var monster in monsters)
        {
            var wait = bot.Inbox.WaitAsync(e => e.IsGame(CombatService.SkillHit)
                && SkillHitInfo.Parser.ParseFrom(e.Body).Dsts.Any(d => d.DstID == monster.Uid && d.IsDead), TimeSpan.FromSeconds(20));
            await bot.SendGameAsync(CombatService.UseSkill, new CastSkillReq { SkillId = 1000001, TargetId = monster.Uid });
            sent.Add((monster.Uid, wait));
            await Task.Delay(20); // close enough together to overlap, not so fast the client-side swing gate refuses them
        }

        foreach (var (uid, wait) in sent)
        {
            var hit = await wait; // a timeout here is the repro: something is still holding the lock
            Assert.True(monsters.Single(m => m.Uid == uid).Dead);
        }
    }
}
