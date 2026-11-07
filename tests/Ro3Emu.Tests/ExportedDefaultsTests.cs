using Microsoft.Extensions.Logging.Abstractions;
using Microsoft.Extensions.Options;
using Ro3Emu.Server;
using Ro3Emu.Server.Data;

namespace Ro3Emu.Tests;

/// <summary>Grouped tables keep the defaults of their inner rows through the export (LuaConfigExporter).</summary>
public sealed class ExportedDefaultsTests
{
    private static GameData Data() => new(Options.Create(new ServerOptions { GameDataDir = Path.Combine(ClientData.RepoRoot, "data") }),
        NullLogger<GameData>.Instance);

    /// <summary>_iWeights is Optional (EatChickenDropGroupConfig): TimedBattleService.ChickenItems treats an absent
    /// or zero weight as "never drawn", not an error, so only the group as a whole needs something to draw.</summary>
    [ClientDataTheory]
    [InlineData(601)]
    [InlineData(602)]
    [InlineData(603)]
    public void ASurvivorChestGroupHasSomeRowWithAWeight(int group)
    {
        var rows = Data().Row("EatChickenDropGroupConfig", group)!.Value;
        Assert.Contains(rows.EnumerateArray(), row => GameData.Int(row, "_iWeights") > 0);
    }

    [ClientDataFact]
    public void ANpcDropRowHasItsDefaultPool()
    {
        var rows = Data().Row("NPCDropConfig", 10021)!.Value;
        Assert.Equal(22, GameData.Int(rows.EnumerateObject().First().Value, "_iDropPool1"));
    }
}
