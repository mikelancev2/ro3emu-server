using System.Globalization;
using Romsg;
using Ro3Emu.Server.Maps;

namespace Ro3Emu.Server.Game;

public sealed partial class DungeonService
{
    /// <summary>ActionConfig 613 EnterDungeon_Sever, _iEXECHost 1 (data/config/ActionConfig.json).</summary>
    public const int RegionEnterDungeonAction = 613;

    /// <summary>
    /// A scene-editor region whose action is "613|scene|1|task" enters that story dungeon. The client runs a region's
    /// first action itself only when ActionConfig marks it client-hosted; for any other action OnEnterArea returns false
    /// and the client reports the region with 31209 instead (re/clientB/lua-src/Logic/Asset/Systems/LC_System_Task.lua:2257-2321,
    /// Logic/Area/TriggerArea/LC_TriggerAreaManager.lua:430-498), so a 613 region has no client-side 30400: the server
    /// enters it. Seven regions carry 613 (e.g. sc_pay_fild03_001 1010209001 "613|1021301|1|10102100" for quest 10102100's
    /// [101018,1021301,1]); in each the fourth field is the running quest the region belongs to. Entering only while that
    /// quest runs keeps the region from re-entering the dungeon after the story moved on (inferred); the second field's
    /// meaning (always 1) is unverified and unused. Admission, loading and settlement are 30400's own.
    /// </summary>
    public async Task<bool> EnterFromRegionAsync(GameSession s, MapRegion region)
    {
        var parts = region.Actions.Split('|');
        if (parts.Length < 2 || parts[0] != RegionEnterDungeonAction.ToString(CultureInfo.InvariantCulture)
            || !long.TryParse(parts[1], NumberStyles.Integer, CultureInfo.InvariantCulture, out long scene)) return false;
        long task = parts.Length >= 4 && long.TryParse(parts[3], NumberStyles.Integer, CultureInfo.InvariantCulture, out long t) ? t : 0;
        if (task != 0 && !(s.Tasks?.Infos.TryGetValue(task, out var info) == true && info.Status is TaskState.Running or TaskState.Finish))
            return false;
        var initiator = RegionEntryInitiator(s);
        _log.LogInformation("{Name} enters region {Region}: dungeon {Scene} for task {Task}, initiated by {Initiator}",
            s.Role?.Name, region.Id, scene, task, initiator.Role?.Name);
        await EnterAsync(initiator, new EnterDungeonReq { SceneEntry = scene });
        return true;
    }

    /// <summary>
    /// A server-hosted region has no leader-side 30400 request to fall back to. Route a member's valid story trigger
    /// through the online leader so ordinary party preparation and its leader permission remain authoritative (inferred).
    /// </summary>
    private GameSession RegionEntryInitiator(GameSession s)
    {
        if (s.Role == null) return s;
        long leader = _teams.TeamOf(s.Role.Id).Leader;
        return leader == s.Role.Id ? s : _sessions.All().FirstOrDefault(p => p.Role?.Id == leader) ?? s;
    }
}
