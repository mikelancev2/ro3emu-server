using System.Text.RegularExpressions;
using Ro3Emu.Server.Accounts;

using Microsoft.AspNetCore.Builder;
namespace Ro3Emu.Server.Http;

/// <summary>
/// Account creation for the public website (chronicles-site), loopback only: the site's PHP backend is the
/// only caller, never the internet directly (same pattern as /ro3emu/gm and /ro3emu/reports). The site proxies
/// here instead of a player's browser calling this host directly, so this never needs CORS and the internet
/// never reaches the PBKDF2-hashing registration path except through the site's own validation and CSRF checks.
/// </summary>
public static class AccountEndpoints
{
    private static readonly Regex NamePattern = new("^[A-Za-z0-9_]{3,24}$", RegexOptions.Compiled);

    public static void Map(WebApplication app)
    {
        app.MapPost("/ro3emu/accounts/register", async (HttpRequest request, CredentialLimiter limiter, AccountStore accounts) =>
        {
            if (request.HttpContext.Connection.RemoteIpAddress is not { } ip || !System.Net.IPAddress.IsLoopback(ip))
                return Results.NotFound();
            if (!limiter.TryAcquire(ip)) return Results.StatusCode(StatusCodes.Status429TooManyRequests);
            var form = await request.ReadFormAsync();
            string name = form["name"].ToString().Trim();
            string password = form["password"].ToString();
            if (!NamePattern.IsMatch(name)) return Results.Json(new { ok = false, error = "bad_name" }, statusCode: 400);
            if (password.Length < 8) return Results.Json(new { ok = false, error = "bad_password" }, statusCode: 400);
            var (result, _) = await accounts.SignInAsync(name, password);
            // Ok and BadPassword both mean "name already exists": a registration endpoint must never reveal whether
            // the password guessed for an existing name was right, or it becomes a login oracle.
            return result == AccountStore.LoginResult.Registered
                ? Results.Json(new { ok = true })
                : Results.Json(new { ok = false, error = "name_taken" }, statusCode: 409);
        });

        app.MapGet("/ro3emu/accounts/available", async (HttpRequest request, CredentialLimiter limiter, AccountStore accounts) =>
        {
            if (request.HttpContext.Connection.RemoteIpAddress is not { } ip || !System.Net.IPAddress.IsLoopback(ip))
                return Results.NotFound();
            if (!limiter.TryAcquire(ip)) return Results.StatusCode(StatusCodes.Status429TooManyRequests);
            string name = request.Query["name"].ToString().Trim();
            if (!NamePattern.IsMatch(name)) return Results.Json(new { available = false, error = "bad_name" }, statusCode: 400);
            bool exists = await accounts.NameExistsAsync(name);
            return Results.Json(new { available = !exists });
        });
    }
}
