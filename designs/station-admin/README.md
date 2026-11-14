# Station-admin UI continuation

Prepared 2 November 2026. Continues the light blue Hamtic Water interface shown in the supplied conversation screenshot. These are design mockups with fictional example data, not connected application pages.

## Design direction

A calm, practical workspace for a Hamtic water refilling station, using the existing pale blue navigation and teal actions. Keep the station identity visible on every screen.

| Token | Value | Use |
| --- | --- | --- |
| Water blue | #EAF5FA | Sidebar and selected regions |
| Clear white | #FFFFFF | Working surface |
| Deep blue | #183B56 | Main text and headings |
| Station teal | #087F9C | Primary actions and active navigation |
| Slate | #5F7484 | Secondary text |
| Mist | #DCE8EF | Borders and separators |

Typography: Source Sans 3 throughout; semibold headings, regular body text, tabular numbers for reports. Sentence-case labels. Use status words alongside color.

Desktop composition, with all working text left aligned:

```text
+-----------------+-----------------------------------------------------+
| Hamtic Water    | Guintas Water Station          Station admin       |
|                 +-----------------------------------------------------+
| Overview        | Page title                         Primary action  |
| Orders          | Short task-specific explanation                    |
| Deliveries      |                                                     |
| Customers       | Tabs / filters                                      |
| Products        |                                                     |
| Drivers         | Main table or form              Context / preview  |
| Reports         |                                                     |
| Station settings|                                                     |
+-----------------+-----------------------------------------------------+
```

## Screens

1. **Driver invitations:** Drivers > Invitations; pending, accepted, and expired examples; create-invitation form; copy link and revoke controls. Link generation is distinct from sending an email.
2. **Service areas:** Station settings > Service areas; searchable sample barangays, coverage selections, and explicit effect on new orders. Coverage examples are proposals, not verified service claims.
3. **Priority rules:** Station settings > Priority rules; P = U + A + C, urgency bands, capped aging, institution bonus disabled by default, sample queue, and a saved-rule version.
4. **Order report:** Reports > Orders; date/status filters, internally consistent order totals and example table, CSV and print actions.
5. **Delivery report:** Reports > Deliveries; trip and stop outcomes, rider workload, failed-attempt reason, and empty-container counts.
6. **Payment report:** Reports > Payments; cash received, reconciled amounts, pending reconciliation, and tender/change context.
7. **Revenue report:** Reports > Revenue; reconciled sales by day and product with a chart and matching totals.

## Review against the brief

Preserve the established blue interface instead of introducing a new aesthetic. Avoid a generic tile dashboard: use a meaningful list, selection surface, form, or table for each task. Keep the shell consistent and let the task determine the content. No decorative gradients, all-caps labels, invented operational claims, or Super Admin access to station transactions.

Every screen labels its sample data. Currency is Philippine pesos. Times use Asia/Manila. Report examples share a 26 October–1 November 2026 period. Screens do not imply authentication, email sending, database changes, or working exports.

## Generation

Generate each screen separately with the built-in image generation tool. The complete prompt set is saved alongside the images. Review headings, station identity, field labels, totals, and scope before delivery.
