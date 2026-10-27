using Microsoft.Data.Sqlite;

namespace Ro3Emu.Server.Accounts.Persistence;

internal sealed class DonationSchemaPlan : DbPlan
{
    internal override int Bytes => 128;
    internal override string Label => "donation/schema";
    internal override object Execute(SqliteConnection db, SqliteTransaction tx)
    {
        SchemaSql.Execute(db, tx, """
            CREATE TABLE IF NOT EXISTS website_donation (
                order_id TEXT PRIMARY KEY,
                stripe_session_id TEXT UNIQUE,
                stripe_payment_intent TEXT,
                stripe_event_id TEXT,
                account TEXT NOT NULL,
                role_id INTEGER NOT NULL,
                package_id TEXT NOT NULL,
                crystals INTEGER NOT NULL CHECK(crystals > 0),
                amount_cents INTEGER NOT NULL CHECK(amount_cents > 0),
                currency TEXT NOT NULL,
                status TEXT NOT NULL CHECK(status IN ('pending','paid')),
                created_utc INTEGER NOT NULL,
                paid_utc INTEGER
            );
            CREATE INDEX IF NOT EXISTS website_donation_account ON website_donation(account,created_utc DESC);
            """);
        return 1;
    }
}

internal sealed class DonationPreparePlan(DonationOrder order) : DbPlan
{
    internal override int Bytes => 256 + order.Account.Length * 2 + order.OrderId.Length * 2;
    internal override string Label => "donation/prepare";
    internal override object Execute(SqliteConnection db, SqliteTransaction tx)
    {
        using var owner = DbSql.Command(db, tx,
            "SELECT 1 FROM role WHERE id=$role AND account=$account COLLATE NOCASE AND deleted_time=0");
        owner.Parameters.AddWithValue("$role", order.RoleId);
        owner.Parameters.AddWithValue("$account", order.Account);
        if (owner.ExecuteScalar() is null) return false;
        using var insert = DbSql.Command(db, tx, """
            INSERT INTO website_donation
                (order_id,account,role_id,package_id,crystals,amount_cents,currency,status,created_utc)
            VALUES ($order,$account,$role,$package,$crystals,$amount,$currency,'pending',$created)
            """);
        insert.Parameters.AddWithValue("$order", order.OrderId);
        insert.Parameters.AddWithValue("$account", order.Account);
        insert.Parameters.AddWithValue("$role", order.RoleId);
        insert.Parameters.AddWithValue("$package", order.PackageId);
        insert.Parameters.AddWithValue("$crystals", order.Crystals);
        insert.Parameters.AddWithValue("$amount", order.AmountCents);
        insert.Parameters.AddWithValue("$currency", order.Currency);
        insert.Parameters.AddWithValue("$created", order.CreatedUtc);
        return insert.ExecuteNonQuery() == 1;
    }
}

internal sealed class DonationAttachSessionPlan(string orderId, string sessionId) : DbPlan
{
    internal override int Bytes => 128 + (orderId.Length + sessionId.Length) * 2;
    internal override string Label => "donation/attach-session";
    internal override object Execute(SqliteConnection db, SqliteTransaction tx)
    {
        using var cmd = DbSql.Command(db, tx, """
            UPDATE website_donation SET stripe_session_id=$session
            WHERE order_id=$order AND status='pending'
              AND (stripe_session_id IS NULL OR stripe_session_id=$session)
            """);
        cmd.Parameters.AddWithValue("$order", orderId);
        cmd.Parameters.AddWithValue("$session", sessionId);
        return cmd.ExecuteNonQuery() == 1;
    }
}

internal sealed class DonationReadPlan(string? orderId = null, string? sessionId = null) : DbReadPlan
{
    internal override int Bytes => 128 + (orderId?.Length ?? 0) * 2 + (sessionId?.Length ?? 0) * 2;
    internal override string Label => "donation/read";
    internal override object? Execute(SqliteConnection db, SqliteTransaction tx)
    {
        using var cmd = DbSql.Command(db, tx, orderId is not null
            ? "SELECT order_id,stripe_session_id,stripe_payment_intent,stripe_event_id,account,role_id,package_id,crystals,amount_cents,currency,status,created_utc,paid_utc FROM website_donation WHERE order_id=$value"
            : "SELECT order_id,stripe_session_id,stripe_payment_intent,stripe_event_id,account,role_id,package_id,crystals,amount_cents,currency,status,created_utc,paid_utc FROM website_donation WHERE stripe_session_id=$value");
        cmd.Parameters.AddWithValue("$value", orderId ?? sessionId ?? "");
        using var row = cmd.ExecuteReader();
        return row.Read() ? DonationOrder.Read(row) : null;
    }
}

internal sealed class DonationCompletePlan(string sessionId, string orderId, string paymentIntent,
    string eventId, string packageId, int amountCents, string currency, long paidUtc) : DbPlan
{
    internal override int Bytes => 256 + (sessionId.Length + orderId.Length + paymentIntent.Length + eventId.Length) * 2;
    internal override string Label => "donation/complete";
    internal override object Execute(SqliteConnection db, SqliteTransaction tx)
    {
        var order = (DonationOrder?)new DonationReadPlan(sessionId: sessionId).Execute(db, tx)
            ?? throw new InvalidOperationException("Unknown Stripe Checkout session.");
        if (order.OrderId != orderId || order.PackageId != packageId || order.AmountCents != amountCents
            || !string.Equals(order.Currency, currency, StringComparison.OrdinalIgnoreCase))
            throw new InvalidOperationException("Stripe Checkout session does not match the prepared donation.");
        if (order.Status == "paid") return 0;
        using var cmd = DbSql.Command(db, tx, """
            UPDATE website_donation
            SET status='paid',stripe_payment_intent=$intent,stripe_event_id=$event,paid_utc=$paid
            WHERE order_id=$order AND status='pending'
            """);
        cmd.Parameters.AddWithValue("$intent", paymentIntent);
        cmd.Parameters.AddWithValue("$event", eventId);
        cmd.Parameters.AddWithValue("$paid", paidUtc);
        cmd.Parameters.AddWithValue("$order", orderId);
        return cmd.ExecuteNonQuery();
    }
}
