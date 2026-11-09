using Microsoft.AspNetCore.Builder;
using Microsoft.Data.Sqlite;
using Microsoft.Extensions.DependencyInjection;
using Romsg;
using Ro3Emu.Server;
using Ro3Emu.Server.Game;

namespace Ro3Emu.Tests;

/// <summary>
/// A GM !quest drop forgot CompleteTaskNum without a trace: handing the quest in again reused the dropped delivery's
/// reward-ledger key, and the economy refused it as already committed. TaskRuntimeState.Forgotten (TaskService.cs)
/// keeps every delivery counted, dropped or not, so a later hand-in gets a key that was never paid.
/// </summary>
public sealed class TaskForgottenDeliveryTests : IAsyncLifetime
{
    private const long Task = 1102013; // "Kill Monster A +10 Points": GM !quest done pays it with no other setup.
    private readonly string _dataDir = Path.Combine(Path.GetTempPath(), "ro3emu-forgottask-" + Guid.NewGuid().ToString("N"));
    private WebApplication _app = null!;
    private string _http = "";
    private T Service<T>() where T : notnull => _app.Services.GetRequiredService<T>();

    public async Task InitializeAsync()
    {
        var (httpPort, microPort, gamePort) = TestPorts.FreePorts();
        _app = ServerHost.Create([], o =>
        {
            o.DataDir = _dataDir; o.GameDataDir = Path.Combine(ClientData.RepoRoot, "data");
            o.HttpPort = httpPort; o.MicroGatePort = microPort; o.GameGatePort = gamePort;
        });
        await _app.StartAsync();
        _http = $"http://127.0.0.1:{httpPort}";
    }

    public async Task DisposeAsync()
    {
        await _app.StopAsync(); await _app.DisposeAsync();
        SqliteConnection.ClearAllPools();
        try { Directory.Delete(_dataDir, true); } catch (IOException) { }
    }

    private Task Gm(string command) => QuestProbe.GmAsync(_http, "alice", command);
    private Task<TaskInfo> Done() => QuestProbe.DoneAsync(_app, "alice", Task);

    [ClientDataFact]
    public async Task HandingInADroppedQuestAgainIsPaidNotRefused()
    {
        await using var bot = new Ro3Emu.Bot.BotClient(_http, File.ReadAllText(Path.Combine(_dataDir, "gate_rsa_public.pem")));
        await bot.LoginAsync("alice", "pw"); await bot.EnterGateAsync();
        await bot.EnterWorldAsync((await bot.CreateRoleAsync("Alice")).RoleBaseInfo.Id);

        await Gm($"!quest done {Task}");
        Assert.Equal(TaskState.Delivery, (await Done()).Status);

        await Gm($"!quest drop {Task}");
        await Gm($"!quest done {Task}");
        // Without Forgotten, the second delivery reuses the first one's reward-ledger key: the economy refuses it,
        // ApplyAwardAsync returns false, and the task is left Running (accepted, never delivered) instead of paid.
        Assert.Equal(TaskState.Delivery, (await Done()).Status);
    }
}
