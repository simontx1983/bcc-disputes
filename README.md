# Blue Collar Crypto – Disputes

Community dispute system for BCC trust votes. Page owners can challenge votes they believe are invalid; a panel of Gold and Platinum members reviews each dispute and votes on the outcome. Accepted disputes remove the offending vote and trigger a score recalculation.

---

## Requirements

- BCC Trust Engine plugin (active)
- At least a few Gold or Platinum members in the system for panelist selection

---

## How It Works

1. A page owner spots a vote they believe is fraudulent or invalid.
2. They open the dispute form, select the vote, and write a reason (+ optional evidence URL).
3. The system randomly selects up to **5 Gold/Platinum members** as panelists and emails each one.
4. Panelists visit their queue, read the dispute, and vote **Accept** (remove vote) or **Reject** (keep vote).
5. The first side to reach a **majority** wins. If no majority is reached, the dispute auto-resolves after **7 days** based on whichever side has more votes (ties favour the voter).
6. **Accepted:** The disputed vote is soft-deleted, a score recalculation is queued, and the voter receives a +5 fraud score penalty.
7. **Rejected:** The vote stands. The reporter is notified.

---

## Shortcodes

### `[bcc_dispute_form]`

Renders the dispute management panel for a page owner. Shows all votes on the page with a **Dispute** button per vote, an inline submission form, and the history of previous disputes.

Only logged-in users see the form. The form checks ownership server-side before allowing submission.

**Attributes**

| Attribute | Default | Description |
|---|---|---|
| `page_id` | `0` | PeepSo page ID to manage disputes for. Omit to auto-detect from the current post. |

**Examples**

```
[bcc_dispute_form]
[bcc_dispute_form page_id="42"]
```

---

### `[bcc_dispute_queue]`

Renders the panelist review queue for the currently logged-in user. Lists all disputes where this user has been assigned as a panelist that are still awaiting a decision.

Automatically shows nothing if the user has no pending assignments — safe to embed in a profile sidebar.

**No attributes.**

**Example**

```
[bcc_dispute_queue]
```

---

## REST API

All endpoints require authentication (`X-WP-Nonce` header).

| Method | Endpoint | Who | Description |
|---|---|---|---|
| `POST` | `/wp-json/bcc/v1/disputes` | Page owner | Submit a new dispute |
| `GET` | `/wp-json/bcc/v1/disputes/votes/{page_id}` | Page owner | List all votes on a page (for the dispute form) |
| `GET` | `/wp-json/bcc/v1/disputes/mine` | Page owner | List disputes filed by the current user |
| `GET` | `/wp-json/bcc/v1/disputes/panel` | Panelist | List disputes assigned to the current user |
| `POST` | `/wp-json/bcc/v1/disputes/{id}/vote` | Panelist | Cast an accept or reject vote |
| `POST` | `/wp-json/bcc/v1/disputes/{id}/resolve` | Admin | Force-resolve a dispute immediately |

### Submit a dispute — `POST /wp-json/bcc/v1/disputes`

**Body (JSON)**

| Field | Required | Description |
|---|---|---|
| `vote_id` | Yes | ID of the vote being disputed (integer) |
| `reason` | Yes | Explanation of why the vote is invalid (min 20 chars) |
| `evidence_url` | No | URL to supporting evidence (transaction hash link, screenshot, etc.) |

**Example**

```json
{
  "vote_id": 99,
  "reason": "This downvote came from an account created the same day as the vote with no other activity.",
  "evidence_url": "https://etherscan.io/tx/0xabc..."
}
```

**Response**

```json
{
  "dispute_id": 7,
  "panelists": 5,
  "message": "Dispute submitted. 5 panelists have been notified."
}
```

### Cast a panel vote — `POST /wp-json/bcc/v1/disputes/{id}/vote`

**Body (JSON)**

| Field | Required | Description |
|---|---|---|
| `decision` | Yes | `"accept"` to remove the vote, `"reject"` to keep it |
| `note` | No | Optional reasoning for your decision |

---

## Database Tables

### `{prefix}bcc_disputes`

| Column | Type | Description |
|---|---|---|
| `id` | BIGINT | Primary key |
| `vote_id` | BIGINT | The vote being disputed |
| `page_id` | BIGINT | The page the vote was cast on |
| `reporter_id` | BIGINT | User ID of the page owner who filed the dispute |
| `voter_id` | BIGINT | User ID of the person who cast the disputed vote |
| `reason` | VARCHAR(1000) | Dispute reason text |
| `evidence_url` | VARCHAR(2083) | Optional evidence link |
| `status` | ENUM | `pending` · `reviewing` · `accepted` · `rejected` |
| `panel_accepts` | TINYINT | Running count of Accept votes |
| `panel_rejects` | TINYINT | Running count of Reject votes |
| `panel_size` | TINYINT | Number of panelists assigned (default 5) |
| `created_at` | DATETIME | When the dispute was filed |
| `resolved_at` | DATETIME | When the dispute was resolved |

### `{prefix}bcc_dispute_panel`

| Column | Type | Description |
|---|---|---|
| `id` | BIGINT | Primary key |
| `dispute_id` | BIGINT | References `bcc_disputes.id` |
| `panelist_user_id` | BIGINT | WP user ID of the panelist |
| `decision` | ENUM | `accept` · `reject` · `NULL` (not yet voted) |
| `note` | VARCHAR(500) | Optional note from the panelist |
| `assigned_at` | DATETIME | When the panelist was assigned |
| `voted_at` | DATETIME | When they cast their vote |

---

## Configuration

| Constant | Default | Description |
|---|---|---|
| `BCC_DISPUTES_PANEL_SIZE` | `5` | Number of panelists assigned per dispute |
| `BCC_DISPUTES_TTL_DAYS` | `7` | Days before an unresolved dispute auto-resolves |
| `BCC_DISPUTES_MIN_TIER` | `'gold'` | Minimum reputation tier to be selected as a panelist |

These are defined in `bcc-disputes.php` and can be overridden by re-defining them in `wp-config.php` before the plugin loads.
