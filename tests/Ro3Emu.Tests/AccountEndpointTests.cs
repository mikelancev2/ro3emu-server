using System.Net;
using System.Net.Http.Json;
using System.Text.Json;
using Microsoft.AspNetCore.Builder;
using Microsoft.Extensions.DependencyInjection;
using Ro3Emu.Server;
using Ro3Emu.Server.Accounts;

namespace Ro3Emu.Tests;

/// <summary>The website authenticates through loopback and receives only the account's own character summary.</summary>
public sealed class AccountEndpointTests : IAsyncLifetime
{
    private readonly string _dataDir = Path.Combine(Path.GetTempPath(), "ro3emu-account-http-" + Guid.NewGuid().ToString("N"));
    private WebApplication _app = null!;
    private string _http = "";
    private string _root = "";

    public async Task InitializeAsync()
    {
        var (httpPort, microPort, gamePort) = TestPorts.FreePorts();
        _app = ServerHost.Create([], options =>
        {
            options.DataDir = _dataDir;
            options.GameDataDir = SyntheticData.Dir;
            options.HttpPort = httpPort;
            options.MicroGatePort = microPort;
            options.GameGatePort = gamePort;
        });
        await _app.StartAsync();
        _root = $"http://127.0.0.1:{httpPort}";
        _http = _root + "/ro3emu/accounts";

        var accounts = _app.Services.GetRequiredService<AccountStore>();
        var roles = _app.Services.GetRequiredService<RoleStore>();
        Assert.Equal(AccountStore.LoginResult.Registered, (await accounts.SignInAsync("Alice", "password123")).Result);
        var role = await roles.CreateAsync("Alice", 1, "Aria", 2, 1100, 1,
            RoleStore.SpawnSceneEntry, RoleStore.SpawnX, 0, RoleStore.SpawnZ, 0);
        Assert.NotNull(role);
        var progress = await roles.LoadProgressAsync(role!.Id);
        progress.Career = 1100;
        progress.Level = 42;
        progress.JobLevel = 18;
        progress.Currency[1] = 12345;
        progress.Currency[3] = 2100;
        roles.SaveProgress(role.Id, progress);
    }

    public async Task DisposeAsync()
    {
        await _app.StopAsync();
        await _app.DisposeAsync();
        Microsoft.Data.Sqlite.SqliteConnection.ClearAllPools();
        try { Directory.Delete(_dataDir, true); } catch (IOException) { }
    }

    [Fact]
    public async Task LoginRejectsWrongPasswordWithoutRegisteringAndReturnsOwnCharacters()
    {
        using var client = new HttpClient();
        using var wrong = await client.PostAsync(_http + "/login", Form("Missing", "password123"));
        Assert.Equal(HttpStatusCode.Unauthorized, wrong.StatusCode);

        using var badPassword = await client.PostAsync(_http + "/login", Form("Alice", "wrong-password"));
        Assert.Equal(HttpStatusCode.Unauthorized, badPassword.StatusCode);

        using var response = await client.PostAsync(_http + "/login", Form("alice", "password123"));
        response.EnsureSuccessStatusCode();
        var body = await response.Content.ReadFromJsonAsync<JsonElement>();
        Assert.True(body.GetProperty("ok").GetBoolean());
        Assert.Equal("Alice", body.GetProperty("account").GetString());
        var role = Assert.Single(body.GetProperty("characters").EnumerateArray());
        Assert.Equal("Aria", role.GetProperty("name").GetString());
        Assert.Equal(42, role.GetProperty("level").GetInt32());
        Assert.Equal(18, role.GetProperty("jobLevel").GetInt32());
        Assert.Equal(12345, role.GetProperty("zeny").GetInt64());
        Assert.Equal(2100, role.GetProperty("crystals").GetInt64());
    }

    [Fact]
    public async Task CanonicalAccountCanRefreshProfileWithoutAnotherPasswordHash()
    {
        using var client = new HttpClient();
        var body = await client.GetFromJsonAsync<JsonElement>(_http + "/profile?account=Alice");
        Assert.True(body.GetProperty("ok").GetBoolean());
        Assert.Equal("Aria", Assert.Single(body.GetProperty("characters").EnumerateArray()).GetProperty("name").GetString());
    }

    [Fact]
    public async Task WebsiteRegistrationStoresEmailAndSdkLoginNeverRegistersUnknownAccounts()
    {
        using var client = new HttpClient();
        using var registered = await client.PostAsync(_http + "/register", new FormUrlEncodedContent(new Dictionary<string, string>
        {
            ["name"] = "WebPlayer", ["password"] = "password123", ["email"] = "Player@Example.com",
        }));
        registered.EnsureSuccessStatusCode();
        var profile = await registered.Content.ReadFromJsonAsync<JsonElement>();
        Assert.True(profile.GetProperty("ok").GetBoolean());
        Assert.Equal("WebPlayer", profile.GetProperty("account").GetString());

        var accounts = _app.Services.GetRequiredService<AccountStore>();
        using var db = accounts.Open();
        using var email = db.CreateCommand();
        email.CommandText = "SELECT email FROM account WHERE name='WebPlayer'";
        Assert.Equal("player@example.com", email.ExecuteScalar());

        using var unknown = await client.PostAsync(_root + "/sdk/login", new FormUrlEncodedContent(new Dictionary<string, string>
        {
            ["identity"] = "NotRegistered", ["credential"] = "password123",
        }));
        var rejected = await unknown.Content.ReadFromJsonAsync<JsonElement>();
        Assert.Equal(HttpStatusCode.OK, unknown.StatusCode);
        Assert.Equal(401, rejected.GetProperty("code").GetInt32());
        Assert.False(await accounts.NameExistsAsync("NotRegistered"));
    }

    [Fact]
    public async Task GoogleIdentityRegistrationCanLaterResolveTheCanonicalAccount()
    {
        using var client = new HttpClient();
        using var registered = await client.PostAsync(_http + "/register", new FormUrlEncodedContent(new Dictionary<string, string>
        {
            ["name"] = "GooglePlayer", ["password"] = "password123", ["email"] = "player@example.com",
            ["google_subject"] = "google-subject-123",
        }));
        registered.EnsureSuccessStatusCode();

        var resolved = await client.GetFromJsonAsync<JsonElement>(_http + "/google?subject=google-subject-123");
        Assert.True(resolved.GetProperty("ok").GetBoolean());
        Assert.Equal("GooglePlayer", resolved.GetProperty("account").GetString());

        using var duplicate = await client.PostAsync(_http + "/register", new FormUrlEncodedContent(new Dictionary<string, string>
        {
            ["name"] = "OtherPlayer", ["password"] = "password123", ["email"] = "other@example.com",
            ["google_subject"] = "google-subject-123",
        }));
        Assert.Equal(HttpStatusCode.Conflict, duplicate.StatusCode);
    }

    private static FormUrlEncodedContent Form(string name, string password) => new(new Dictionary<string, string>
    {
        ["name"] = name,
        ["password"] = password,
    });
}
