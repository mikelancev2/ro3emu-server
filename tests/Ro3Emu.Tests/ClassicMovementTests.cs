using Google.Protobuf;
using Romsg;
using Ro3Emu.Server.Game;

namespace Ro3Emu.Tests;

/// <summary>
/// Classic mode walks by mouse: a role that kept Classic through the mode panel without its keyboard settings logged in
/// with mouse movement off (no ground cell, clicks did not walk). RoleSessionService.RepairClassicMovement turns it on.
/// </summary>
public sealed class ClassicMovementTests
{
    private static DBSettingInfo Settings(int gameMode, KeyBoardInfo? keys)
    {
        var s = new DBSettingInfo();
        s.ClientSetting[(int)SettingType.GameMode] = new GameModeSetting { GameMode = gameMode, PcPlatformRecord = true }.ToByteString();
        if (keys != null) s.ClientSetting[(int)SettingType.KeyBoard] = keys.ToByteString();
        return s;
    }

    private static KeyBoardInfo Keys(DBSettingInfo s) => KeyBoardInfo.Parser.ParseFrom(s.ClientSetting[(int)SettingType.KeyBoard]);

    [Fact]
    public void ClassicWithoutKeyboardSettingsGetsBothMovementFlags()
    {
        var s = Settings(1, null);
        Assert.True(RoleSessionService.RepairClassicMovement(s));
        Assert.True(Keys(s).EnableKeyboardMove);
        Assert.True(Keys(s).EnableMouseMove);
        Assert.Equal(1, Keys(s).KeyBoardMode);
    }

    [Fact]
    public void ClassicWithModernKeyboardSettingsGetsBothMovementFlags()
    {
        var s = Settings(1, new KeyBoardInfo { EnableKeyboardMove = true, OverrideBindingJson = "{}" });
        Assert.True(RoleSessionService.RepairClassicMovement(s));
        Assert.True(Keys(s).EnableKeyboardMove);
        Assert.True(Keys(s).EnableMouseMove);
        Assert.Equal("{}", Keys(s).OverrideBindingJson);
    }

    /// <summary>
    /// A proto3 bool left at its default (false) is never written to the wire, so the client decodes it back as
    /// nil, not false: a repair that only set EnableMouseMove left EnableKeyboardMove at that default, which
    /// _processKeyBoardInfo then reads as nil and passes into SetEnableKeyboardMove before it ever reaches
    /// SetEnableMouseMove. Both flags must end up explicitly true, not just saved as true/default.
    /// </summary>
    [Fact]
    public void RepairSetsEnableKeyboardMoveExplicitlyNotJustMouseMove()
    {
        var s = Settings(1, null);
        Assert.True(RoleSessionService.RepairClassicMovement(s));
        var keyBytes = s.ClientSetting[(int)SettingType.KeyBoard].Span;
        using var input = new CodedInputStream(keyBytes.ToArray());
        bool sawEnableKeyboardMove = false;
        for (uint tag; (tag = input.ReadTag()) != 0;)
        {
            if (tag == (4 << 3 | 0)) { sawEnableKeyboardMove = true; input.ReadBool(); } // EnableKeyboardMove = 4, varint
            else input.SkipLastField();
        }
        Assert.True(sawEnableKeyboardMove);
    }

    [Fact]
    public void HealthyOrModernSettingsAreLeftAlone()
    {
        Assert.False(RoleSessionService.RepairClassicMovement(
            Settings(1, new KeyBoardInfo { KeyBoardMode = 1, EnableKeyboardMove = true, EnableMouseMove = true })));
        Assert.False(RoleSessionService.RepairClassicMovement(Settings(2, new KeyBoardInfo { EnableKeyboardMove = true })));
        Assert.False(RoleSessionService.RepairClassicMovement(new DBSettingInfo()));
    }
}
