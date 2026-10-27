# Monetization readiness

This document records the monetization systems already present in RO3Emu, the policy currently used by the
Chronicles realm, and the work required before accepting real-money payments. It is a technical readiness guide,
not a promise that every client-configured product reproduces an original live-service offer.

## Current Chronicles policy

The production service currently uses:

```text
Ro3Emu__OpenContent__Enabled=true
Ro3Emu__Pass__Play=true
Ro3Emu__Pass__FreePremium=true
Ro3Emu__OpenContent__ClosedActivities=[1011,1012,1013,1014,200,201]
```

Consequences:

- Pass I (activity 200) and Pass II (activity 201) are closed;
- the Novice Pass (activity 9201) remains open and free, including its premium track, and does not award crystals;
- the saved progress, claim markers and rewards already received from the closed passes remain intact;
- content windows and selected otherwise-unreachable functions are opened by the realm policy;
- opening content does not grant levels, materials, currencies, appearances or completed objectives;
- real-money order, payment-result, first-recharge and paid item-exchange messages are refused with
  `FeatureUnavailable`.

There is currently no working real-money purchase path and no player can obtain an in-game advantage merely by
sending a client-authored payment message.

## Existing systems

| System | Game-server readiness | Current policy or limitation |
| --- | --- | --- |
| Sacred Relic Crystal shop | Purchases, payment debit, item/appearance grant, limits and persistence work | No real-money crystal delivery exists |
| Battle Pass | Progress, free/premium claims, premium entitlement consumption, level purchase and persistence work | Only the free Novice Pass is currently open; Pass I and Pass II are closed |
| Month Card | Entitlement consumption, 30-day duration, renewal, daily claims and duplicate protection work | Entitlement tokens have no normal purchase path |
| Appearance shop | Outfits, hair, appearance parts and linked mounts can be unlocked transactionally | Requires an owned configured currency balance |
| Battle Pass gift | Gift-token debit and claimable-mail delivery work | Gift token has no normal purchase path |
| Product bundles | `ProductConfig` and client presentation data exist | Server payment and order fulfillment do not exist |
| VIP | Client config and protocol fields exist | No functional VIP progression, benefits or gates exist on the server |

## Battle Pass

The implemented passes are:

- activity 200, Pass I: 60 levels;
- activity 201, Pass II: 60 levels and, with `Pass:Play`, opens after Pass I reaches its last level;
- activity 9201, Novice Pass: 30 levels and gameplay progression from level 10.

The Chronicles realm currently closes activities 200 and 201 and keeps only activity 9201 available. Closing an
activity prevents new progression and claims without deleting the saved state, so both paid-pass candidates can be
reopened later. Rewards and crystals claimed before the closure remain owned.

Pass EXP may come from daily and weekly challenges, daily activity, monster kills, dungeon clears, MVP fights and
story chapter completion. Passes 200 and 201 require 2,000 EXP per configured level. Their configured level purchase
cost is 10 Zeny per level; this is not a cash payment.

If Pass I and Pass II are reopened, their configured free and premium tracks contain:

- Pass I: 200 crystals from the free track and 1,900 from the premium track, totaling 2,100;
- Pass II: 1,800 crystals from the free track and 1,900 from the premium track, totaling 3,700;
- Pass I and II together: 5,800 Sacred Relic Crystals.

To sell one of these premium passes later:

1. keep activities 200 and 201 closed while payment support is incomplete;
2. set `Ro3Emu__Pass__FreePremium=false` before reopening a paid pass, noting that this option applies globally;
3. sell commodity 13125: one character-bound item 703 entitlement for 1,000 Sacred Relic Crystals;
4. deliver it only after a verified payment webhook;
5. retain the existing server-side consumption and activation flow;
6. review existing free crystal rewards and premium-track value before launch.

Until a tested purchase and recovery path exists, Pass I and Pass II stay unavailable. The Novice Pass remains free,
and existing rewards from all passes remain owned with their claim markers recorded.

## Sacred Relic Crystals and the shop

Item IDs 3 and 16 are named `Sacred Relic Crystal` by the client. A new role currently receives starter items and
1,000 Zeny, but no crystals.

`ShopCommodityConfig` contains approximately 64 commodities priced with currency 3. The implemented shop can sell
configured consumables, materials, outfits, hairstyles and mounts. It resolves the authoritative price from server
data, debits the currency, grants the product and saves the purchase counter in one transaction.

Examples in the current client data include:

- Tea Party appearance: 1,880 crystals;
- Macaron hairstyle: 480 crystals;
- Big Poring appearance and linked mount: 980 crystals.

The currently active Novice Pass grants no crystals. Pass I and Pass II are closed; if reopened with their present
rewards, their free tracks would grant 2,000 crystals and their premium tracks another 3,800 crystals.
Before selling crystals, classify every commodity as one of:

- cosmetic;
- convenience;
- progression material;
- competitive advantage;
- unavailable or incomplete.

Cosmetics are the safest initial catalog. Progression and competitive products require an explicit balance policy.

## Month Cards

The server recognizes two 30-day entitlements:

| Card | Entitlement item | Initial configured award | Configured daily award |
| --- | ---: | ---: | ---: |
| Standard | 701 | 500 crystals | 100 crystals |
| Premium | 702 | 1,000 crystals | 200 crystals |

The server consumes an owned entitlement, records its expiry, allows renewal within the configured 143-day cap and
prevents a second daily claim, including after reconnect or restart.

No payment path currently awards items 701 or 702. Until one exists, these cards are inaccessible through ordinary
play rather than purchasable. An operator grant can test them, but it is not a production sales mechanism.

## VIP

`VipConfig` and VIP protocol fields are client artifacts only at present. The server does not:

- accumulate qualifying spending;
- assign or synchronize a functional VIP level;
- grant VIP benefits;
- enforce VIP-only content gates.

VIP would be a new feature, not a configuration switch. It should not be the first monetization milestone.

## Required payment architecture

The client must never be trusted to report that it paid. The current refusal of client-authored order and payment
messages must remain in place unless those messages are backed by authoritative server-side order state.

A production integration needs:

1. a product catalog controlled by Chronicles, with stable SKU IDs and prices;
2. authenticated checkout on the website;
3. a payment provider supporting Pix and, optionally, cards;
4. signed webhook verification on the server;
5. a durable order table with provider ID, account, SKU, amount, currency and state;
6. idempotent fulfillment so one payment can be delivered only once;
7. server-side delivery of crystals or entitlement items;
8. an auditable ledger connecting payment, delivery and in-game receipt;
9. reconciliation for delayed or duplicated webhooks;
10. refund, cancellation and chargeback policy;
11. operator tools for support that cannot silently duplicate delivery;
12. monitoring and alerts for failed or inconsistent fulfillment.

Credentials and payment-provider secrets must stay outside Git and outside the web document root.

## Recommended rollout

1. Review and classify the crystal-shop catalog.
2. Launch cosmetic-only crystal purchases through Pix.
3. Add the Standard and Premium Month Cards.
4. Test paid Battle Pass entitlement purchase and activation before offering it to players.
5. Add gift products and carefully selected bundles.
6. Consider VIP only after its benefits, limits and fairness policy are designed and implemented.

Before each phase, test successful payment, duplicated webhook, delayed webhook, server restart during fulfillment,
inventory capacity, refund and support recovery. A payment must never be accepted without a durable and recoverable
delivery record.

## Fairness decisions required before launch

- How many crystals remain obtainable through normal gameplay?
- Are paid crystals and earned crystals interchangeable?
- Which products are cosmetic-only?
- Can money accelerate combat power, and if so, what limit applies?
- Does a paid pass begin immediately or only on a new season boundary?
- What happens to an entitlement purchased near the end of a season?
- Are purchases account-wide or character-specific?
- How are refunds handled after currency or an entitlement has been consumed?
- What regional prices, taxes, terms and age protections apply?

These decisions should be made before connecting a payment provider, because they determine the order model,
fulfillment behavior and player-facing terms.

## Relevant implementation references

- `src/Ro3Emu.Server/Game/Messages/CoreMessages.cs`: refusal of client-authored payment operations.
- `src/Ro3Emu.Server/Game/ShopService.cs`: configured shop purchases and transactional settlement.
- `src/Ro3Emu.Server/Game/NewActivityService.BattlePass.cs`: pass claims, premium activation, gifts and level purchase.
- `src/Ro3Emu.Server/Game/NewActivityService.MonthCard.cs`: card activation, expiry and daily claims.
- `src/Ro3Emu.Server/ServerOptions.cs`: `OpenContent` and `Pass` policy switches.
- `docs/systems/activities-battlepass-monthcard.md`: detailed client evidence and emulator behavior.
