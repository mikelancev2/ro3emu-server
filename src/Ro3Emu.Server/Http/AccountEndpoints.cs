using System.Text.RegularExpressions;
using Ro3Emu.Server.Accounts;
using Ro3Emu.Server.Data;
using Ro3Emu.Server.Data.Config;
using Ro3Emu.Server.Game;

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
    private const int Zeny = 1, SacredRelicCrystal = 3, BoundSacredRelicCrystal = 16;
    private static readonly Regex NamePattern = new("^[A-Za-z0-9_]{3,24}$", RegexOptions.Compiled);
    private static readonly Regex GoogleSubjectPattern = new("^[A-Za-z0-9_-]{1,255}$", RegexOptions.Compiled);

    public static void Map(WebApplication app)
    {
        app.MapPost("/ro3emu/accounts/register", async (HttpRequest request, CredentialLimiter limiter, AccountStore accounts,
            RoleStore roles, GameData data, SessionRegistry sessions, GameLoop loop) =>
        {
            if (request.HttpContext.Connection.RemoteIpAddress is not { } ip || !System.Net.IPAddress.IsLoopback(ip))
                return Results.NotFound();
            if (!limiter.TryAcquire(ip)) return Results.StatusCode(StatusCodes.Status429TooManyRequests);
            var form = await request.ReadFormAsync();
            string name = form["name"].ToString().Trim();
            string password = form["password"].ToString();
            string email = form["email"].ToString().Trim();
            string googleSubject = form["google_subject"].ToString();
            if (!NamePattern.IsMatch(name)) return Results.Json(new { ok = false, error = "bad_name" }, statusCode: 400);
            if (password.Length is < 8 or > 256) return Results.Json(new { ok = false, error = "bad_password" }, statusCode: 400);
            if (email.Length is < 3 or > 254 || !System.Net.Mail.MailAddress.TryCreate(email, out _))
                return Results.Json(new { ok = false, error = "bad_email" }, statusCode: 400);
            if (googleSubject.Length > 0 && !GoogleSubjectPattern.IsMatch(googleSubject))
                return Results.Json(new { ok = false, error = "bad_google_identity" }, statusCode: 400);
            if (!await accounts.RegisterAsync(name, password, email,
                googleSubject.Length == 0 ? null : "google", googleSubject.Length == 0 ? null : googleSubject))
                return Results.Json(new { ok = false, error = "name_or_email_taken" }, statusCode: 409);
            return Results.Json(await ProfileAsync(name, roles, data, sessions, loop));
        });

        app.MapGet("/ro3emu/accounts/google", async (HttpRequest request, AccountStore accounts) =>
        {
            if (!Loopback(request.HttpContext)) return Results.NotFound();
            string subject = request.Query["subject"].ToString();
            if (!GoogleSubjectPattern.IsMatch(subject)) return Results.BadRequest();
            string? account = await accounts.ExternalIdentityAccountAsync("google", subject);
            return account is null ? Results.NotFound() : Results.Json(new { ok = true, account });
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

        app.MapPost("/ro3emu/accounts/login", async (HttpRequest request, CredentialLimiter limiter,
            AccountStore accounts, RoleStore roles, GameData data, SessionRegistry sessions, GameLoop loop) =>
        {
            if (!Loopback(request.HttpContext)) return Results.NotFound();
            var ip = ClientAddress(request);
            if (!limiter.TryAcquire(ip)) return Results.StatusCode(StatusCodes.Status429TooManyRequests);
            var form = await request.ReadFormAsync();
            string name = form["name"].ToString().Trim();
            string password = form["password"].ToString();
            if (!NamePattern.IsMatch(name) || password.Length is < 1 or > 256
                || await accounts.VerifiedAccountAsync(name, password) is not { } identity)
                return Results.Json(new { ok = false, error = "invalid_credentials" }, statusCode: 401);
            return Results.Json(await ProfileAsync(identity, roles, data, sessions, loop));
        });

        app.MapGet("/ro3emu/accounts/profile", async (HttpRequest request, RoleStore roles, GameData data,
            SessionRegistry sessions, GameLoop loop) =>
        {
            if (!Loopback(request.HttpContext)) return Results.NotFound();
            string account = request.Query["account"].ToString();
            if (!NamePattern.IsMatch(account))
                return Results.Json(new { ok = false, error = "bad_account" }, statusCode: 400);
            return Results.Json(await ProfileAsync(account, roles, data, sessions, loop));
        });

        MapDonations(app);
    }

    private static void MapDonations(WebApplication app)
    {
        app.MapPost("/ro3emu/accounts/donations/prepare", async (HttpRequest request, DonationStore donations) =>
        {
            if (!Loopback(request.HttpContext)) return Results.NotFound();
            var form = await request.ReadFormAsync();
            string account = form["account"].ToString();
            string package = form["package"].ToString();
            if (!NamePattern.IsMatch(account) || !long.TryParse(form["role"], out long role) || role <= 0)
                return Results.Json(new { ok = false, error = "bad_order" }, statusCode: 400);
            var order = await donations.PrepareAsync(account, role, package);
            return order is null
                ? Results.Json(new { ok = false, error = "bad_order" }, statusCode: 400)
                : Results.Json(new
                {
                    ok = true, orderId = order.OrderId, order.PackageId,
                    order.Crystals, order.AmountCents, order.Currency
                });
        });

        app.MapPost("/ro3emu/accounts/donations/attach", async (HttpRequest request, DonationStore donations) =>
        {
            if (!Loopback(request.HttpContext)) return Results.NotFound();
            var form = await request.ReadFormAsync();
            string order = form["order_id"].ToString();
            string session = form["session_id"].ToString();
            if (!OrderId(order) || !StripeId(session, "cs_")) return Results.BadRequest();
            return await donations.AttachSessionAsync(order, session) ? Results.Ok() : Results.BadRequest();
        });

        app.MapPost("/ro3emu/accounts/donations/complete", async (HttpRequest request, DonationStore donations) =>
        {
            if (!Loopback(request.HttpContext)) return Results.NotFound();
            var form = await request.ReadFormAsync();
            string session = form["session_id"].ToString(), order = form["order_id"].ToString();
            string intent = form["payment_intent"].ToString(), eventId = form["event_id"].ToString();
            string package = form["package_id"].ToString();
            string currency = form["currency"].ToString().ToLowerInvariant();
            if (!StripeId(session, "cs_") || !OrderId(order) || !StripeId(intent, "pi_")
                || !StripeId(eventId, "evt_") || !int.TryParse(form["amount_cents"], out int amount) || amount <= 0)
                return Results.BadRequest();
            bool completed = await donations.CompleteAsync(session, order, intent, eventId, package, amount, currency);
            return completed ? Results.Ok() : Results.BadRequest();
        });

        app.MapGet("/ro3emu/accounts/donations/status", async (HttpRequest request, DonationStore donations) =>
        {
            if (!Loopback(request.HttpContext)) return Results.NotFound();
            string orderId = request.Query["order_id"].ToString();
            if (!OrderId(orderId) || await donations.FindOrderAsync(orderId) is not { } order)
                return Results.NotFound();
            return Results.Json(new
            {
                ok = true, order.OrderId, order.Account, order.RoleId, order.PackageId,
                order.Crystals, order.AmountCents, order.Currency, order.Status
            });
        });
    }

    private static bool OrderId(string value) => value.Length == 32 && value.All(Uri.IsHexDigit);
    private static bool StripeId(string value, string prefix) => value.StartsWith(prefix, StringComparison.Ordinal)
        && value.Length is >= 8 and <= 255 && value[prefix.Length..].All(c => char.IsAsciiLetterOrDigit(c) || c == '_');

    private static bool Loopback(HttpContext context) =>
        context.Connection.RemoteIpAddress is { } ip && System.Net.IPAddress.IsLoopback(ip);

    private static System.Net.IPAddress ClientAddress(HttpRequest request) =>
        System.Net.IPAddress.TryParse(request.Headers["X-Chronicles-Client-IP"].ToString(), out var forwarded)
            ? forwarded : request.HttpContext.Connection.RemoteIpAddress!;

    private static async Task<object> ProfileAsync(string account, RoleStore roles, GameData data,
        SessionRegistry sessions, GameLoop loop)
    {
        var characters = await roles.ForAccountAsync(account);
        var progress = await Task.WhenAll(characters.Select(role => roles.LoadProgressAsync(role.Id)));
        var online = await loop.InvokeAsync(() => Task.FromResult(sessions.All()
            .Where(session => session.Role != null).Select(session => session.Role!.Id).ToHashSet()));
        return new
        {
            ok = true,
            account,
            characters = characters.Select((role, index) =>
            {
                var current = progress[index];
                var job = JobConfig.Get(data, current.Career);
                string career = data.Text(job?.Name.FirstOrDefault() ?? 0);
                return new
                {
                    id = role.Id,
                    role.Name,
                    role.Sex,
                    careerId = current.Career,
                    career,
                    level = current.Level,
                    jobLevel = current.JobLevel,
                    paragonLevel = current.ParagonLevel,
                    zeny = current.Currency.GetValueOrDefault(Zeny),
                    crystals = current.Currency.GetValueOrDefault(SacredRelicCrystal)
                        + current.Currency.GetValueOrDefault(BoundSacredRelicCrystal),
                    lastLogin = role.LastLoginTime,
                    online = online.Contains(role.Id),
                };
            }).ToArray(),
        };
    }
}
