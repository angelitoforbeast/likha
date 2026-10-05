# Result 011: The owner private table always fits the screen width, with a switch back to full size

Status: **in progress** on branch `feat/011-fit-to-width`, cut from `develop` at `a3ef772`.

## Plan

1. First commit: this handoff saved verbatim, this file, and the row in `handoff/README.md`.
2. Test first (`tests/Feature/OwnerPrivate/FitToWidthTest.php`): the main page carries the helper and the switch exactly once for the CEO and for Marketing, the out-of-scope pages do not, and the pure factor function is in the source with its guards.
3. One partial `resources/views/owner/_fit_to_width.blade.php` (switch, style, plain script): CSS `zoom` on the table's card with one factor = container width / natural table width (never above 1), remembered in localStorage; included once in the toolbar of `owner/private.blade.php`, plus one marker attribute on the card. The breakdown page is left alone if it needs special handling (D6).
4. Recompute hooks inside the partial: ResizeObserver on the scroll container and the table, MutationObserver on the table (Alpine changes), window resize; one measurement per animation frame, written only when the factor changes (no loop).
5. `skeptic-reviewer` at standard depth with the two named checks, fixes or `TODO.md`, the full suite once, evidence per done-when item in this file; no push, no PR, no deploy.

## Summary

Pending.

## Done-when evidence

Pending.

## Technique and its costs

Pending.

## Recompute triggers

Pending.

## Floating elements

Pending.

## Browser checklist for Mira

Pending.

## Review findings

Pending.

## Rulings

Pending.

## Deploy notes for Mira

Pending.

## Proposed tasks

Pending.
