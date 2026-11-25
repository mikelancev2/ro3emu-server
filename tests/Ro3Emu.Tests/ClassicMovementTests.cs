using Google.Protobuf;
using Romsg;
using Ro3Emu.Server.Game;

namespace Ro3Emu.Tests;

/// <summary>
/// Classic mode walks by mouse: a role that kept Classic through the mode panel without its keyboard settings logged in
/// with mouse movement off (no ground cell, clicks did not walk). RoleSessionService.RepairClassicMovement turns it on by
/// writing exactly the Nostalgic defaults the mode panel itself would have saved (ClientConfig.lua, PCInteractSetting).
/// It only fills that exact gap -- absent, corrupt, or our own historical bug's exact wire signature -- any other saved
/// KeyBoardInfo, through the mode panel or the Button tab (UI_Sys_Setup_ButtonWidgetCtrl.m_bNowEnableMouseMove), is the
/// player's own choice and is left alone.
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
    public void ClassicWithoutKeyboardSettingsGetsTheNostalgicDefaults()
    {
        var s = Settings(1, null);
        Assert.True(RoleSessionService.RepairClassicMovement(s));
        var keys = Keys(s);
        Assert.True(keys.EnableMouseMove);
        Assert.False(keys.EnableKeyboardMove);
        Assert.Equal(1, keys.KeyBoardMode);
        Assert.Equal(0, keys.SelectBtn); // enum_PCMouseBtn.Left
        Assert.Equal(0, keys.MoveBtn); // enum_PCMouseBtn.Left
        Assert.Equal(0, keys.CameraMode); // enum_PCCameraDragMode.RightDrag
    }

    [Fact]
    public void ClassicWithAnyAlreadySavedKeyboardInfoIsLeftAlone()
    {
        var s = Settings(1, new KeyBoardInfo { EnableKeyboardMove = true, OverrideBindingJson = "{}" });
        Assert.False(RoleSessionService.RepairClassicMovement(s));
        Assert.Equal("{}", Keys(s).OverrideBindingJson);
    }

    [Fact]
    public void OurOwnOldBuggyWireValuesAreMigrated()
    {
        var s = Settings(1, new KeyBoardInfo { KeyBoardMode = 1, EnableMouseMove = true, SelectBtn = 1, MoveBtn = 1, CameraMode = 2 });
        Assert.True(RoleSessionService.RepairClassicMovement(s));
        var keys = Keys(s);
        Assert.Equal(0, keys.SelectBtn);
        Assert.Equal(0, keys.MoveBtn);
        Assert.Equal(0, keys.CameraMode);
        Assert.True(keys.EnableMouseMove);
    }

    [Fact]
    public void SavedMouseOffIsNeverOverwritten()
    {
        var s = Settings(1, new KeyBoardInfo { KeyBoardMode = 1, EnableMouseMove = false, EnableKeyboardMove = true, SelectBtn = 0, MoveBtn = 0, CameraMode = 0 });
        Assert.False(RoleSessionService.RepairClassicMovement(s));
        var keys = Keys(s);
        Assert.False(keys.EnableMouseMove);
        Assert.True(keys.EnableKeyboardMove);
    }

    [Fact]
    public void CorruptKeyBoardIsRepairedLikeMissing()
    {
        var s = Settings(1, null);
        s.ClientSetting[(int)SettingType.KeyBoard] = ByteString.CopyFrom(0xFF, 0xFF, 0xFF);
        Assert.True(RoleSessionService.RepairClassicMovement(s));
        Assert.True(Keys(s).EnableMouseMove);
        Assert.Equal(0, Keys(s).CameraMode);
    }

    [Fact]
    public void ModernModeOrNoGameModeIsLeftAlone()
    {
        Assert.False(RoleSessionService.RepairClassicMovement(Settings(1, new KeyBoardInfo { KeyBoardMode = 1, EnableMouseMove = true, SelectBtn = 0, MoveBtn = 0, CameraMode = 0 })));
        Assert.False(RoleSessionService.RepairClassicMovement(Settings(1, new KeyBoardInfo { KeyBoardMode = 1, EnableMouseMove = true, SelectBtn = 0, MoveBtn = 0, CameraMode = 1 })));
        Assert.False(RoleSessionService.RepairClassicMovement(Settings(2, new KeyBoardInfo { EnableKeyboardMove = true })));
        Assert.False(RoleSessionService.RepairClassicMovement(new DBSettingInfo()));
    }
}
