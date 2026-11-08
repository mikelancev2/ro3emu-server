namespace Ro3Emu.Server.Game;

/// <summary>
/// Temporary diagnostic for the production Character.StateLock deadlock (2026-11-08): every CharacterService.Lock
/// wait/hold/release/failure, append-only to a file, so an unreleased HELD line survives past process restarts and
/// points straight at the caller that never disposed its lock. Remove once the deadlock is found and fixed.
/// </summary>
internal static class StateLockTrace
{
    internal static long Seq;
    private static readonly string Path = System.IO.Path.Combine(System.IO.Path.GetTempPath(), "statelock-trace.log");
    private static readonly object Gate = new();

    internal static void Log(string line)
    {
        try
        {
            lock (Gate)
                File.AppendAllText(Path, $"{DateTime.UtcNow:O} {line}{Environment.NewLine}");
        }
        catch { /* diagnostic only: never let a trace-write failure break the lock it is watching */ }
    }
}
