using Microsoft.Data.Sqlite;
using Ro3Emu.Server.Accounts.Persistence;
using Ro3Emu.Server.Game;

namespace Ro3Emu.Server.Accounts;

public sealed record DonationPackage(string Id, int ItemEntry, int ItemCount, int AmountCents);

public sealed record DonationOrder(string OrderId, string? StripeSessionId, string? StripePaymentIntent,
    string? StripeEventId, string Account, long RoleId, string PackageId, int ItemEntry, int ItemCount, int AmountCents,
    string Currency, string Status, long CreatedUtc, long? PaidUtc)
{
    internal static DonationOrder Read(SqliteDataReader row) => new(
        row.GetString(0), row.IsDBNull(1) ? null : row.GetString(1), row.IsDBNull(2) ? null : row.GetString(2),
        row.IsDBNull(3) ? null : row.GetString(3), row.GetString(4), row.GetInt64(5), row.GetString(6),
        row.GetInt32(7), row.GetInt32(8), row.GetInt32(9), row.GetString(10), row.GetString(11), row.GetInt64(12),
        row.IsDBNull(13) ? null : row.GetInt64(13));
}

/// <summary>Prepared Stripe orders and idempotent, durable delivery of purchased items (crystals, month cards).</summary>
public sealed class DonationStore(AccountStore accounts, MailService mail)
{
    private const int SacredRelicCrystal = 3;

    /// <summary>MonthCardConfig's _iItem for id 1001 (Standard) and 1002 (Premium).</summary>
    public const int StandardMonthCard = 701, PremiumMonthCard = 702;

    private const int DeliveryMailTemplate = 1045;
    private const string Currency = "brl";
    private readonly AccountStore _accounts = accounts;
    private readonly MailService _mail = mail;

    public static IReadOnlyDictionary<string, DonationPackage> Packages { get; } = new Dictionary<string, DonationPackage>
    {
        ["crystals-500"] = new("crystals-500", SacredRelicCrystal, 500, 990),
        ["crystals-1000"] = new("crystals-1000", SacredRelicCrystal, 1_000, 1_890),
        ["crystals-2500"] = new("crystals-2500", SacredRelicCrystal, 2_500, 4_490),
        ["crystals-5000"] = new("crystals-5000", SacredRelicCrystal, 5_000, 8_490),
        ["crystals-10000"] = new("crystals-10000", SacredRelicCrystal, 10_000, 15_990),
        ["crystals-20000"] = new("crystals-20000", SacredRelicCrystal, 20_000, 29_990),
        ["monthcard-standard"] = new("monthcard-standard", StandardMonthCard, 1, 790),
        ["monthcard-premium"] = new("monthcard-premium", PremiumMonthCard, 1, 2490),
    };

    public async Task<DonationOrder?> PrepareAsync(string account, long roleId, string packageId)
    {
        if (!Packages.TryGetValue(packageId, out var package)) return null;
        var order = new DonationOrder(Guid.NewGuid().ToString("N"), null, null, null, account, roleId,
            package.Id, package.ItemEntry, package.ItemCount, package.AmountCents, Currency, "pending",
            DateTimeOffset.UtcNow.ToUnixTimeSeconds(), null);
        return await _accounts.WriteAsync<bool>(new DonationPreparePlan(order)) ? order : null;
    }

    public Task<bool> AttachSessionAsync(string orderId, string sessionId) =>
        _accounts.WriteAsync<bool>(new DonationAttachSessionPlan(orderId, sessionId));

    public Task<DonationOrder?> FindOrderAsync(string orderId) =>
        _accounts.ReadAsync<DonationOrder?>(new DonationReadPlan(orderId: orderId));

    public Task<DonationOrder?> FindSessionAsync(string sessionId) =>
        _accounts.ReadAsync<DonationOrder?>(new DonationReadPlan(sessionId: sessionId));

    public async Task<bool> CompleteAsync(string sessionId, string orderId, string paymentIntent,
        string eventId, string packageId, int amountCents, string currency)
    {
        var order = await FindSessionAsync(sessionId);
        if (order is null || order.OrderId != orderId) return false;
        using var held = await _mail.LockRecipientsAsync([order.RoleId]);
        var transaction = new RoleItemPlanBuilder(_accounts);
        transaction.Add(new DonationCompletePlan(sessionId, orderId, paymentIntent, eventId, packageId,
            amountCents, currency, DateTimeOffset.UtcNow.ToUnixTimeSeconds()));
        _mail.QueueTemplateLocked(order.RoleId, $"stripe:{sessionId}", DeliveryMailTemplate,
            [new MailService.Attachment(order.ItemEntry, order.ItemCount)], transaction);
        await transaction.CommitAsync(_accounts, "donation/complete");
        return true;
    }
}
