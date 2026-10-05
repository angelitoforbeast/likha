# Result 012: The CEO account stays logged in for 30 days

Status: **in progress** on branch `feat/012-ceo-stay-logged-in`, cut from `develop` at `9332e43`.

## Plan

1. First commit: this handoff saved verbatim, this file, and the row in `handoff/README.md`.
2. Tests first (`tests/Feature/Auth/`): pin today's login, failure and logout behaviour for the non-CEO roles, then the red tests of section 5 (CEO remember cookie with a 30-day expiry, sign-in from the cookie alone, refusal after a role change or a removed profile, logout cycles the token, a tampered cookie signs nobody in).
3. `LoginController::login()`: the role of the account behind the submitted e-mail decides the `remember` argument of the same `Auth::attempt()` call; for a CEO the guard's remember duration is set to 43,200 minutes (30 days) first. No request parameter is read; `session()->regenerate()` and the redirect stay as they are.
4. D3 as one listener class on the framework's `Login` event (`app/Listeners/`, next to the existing `CopyEverydayTasksOnLogin`, picked up by the framework's listener discovery so there is no registration line): when the sign-in came from the remember cookie (`Auth::viaRemember()`) and the account is not CEO, `Auth::logout()`. It runs inside the guard at the moment of the cookie sign-in, so no route or middleware order can skip it.
5. `skeptic-reviewer` at adversarial depth (high tier) with the three named checks, fixes or `TODO.md`, the full suite once at the end, evidence per done-when item in this file; no push, no PR, no deploy.

## Summary

Pending.

## Done-when evidence

Pending.

## Every way to become signed in

Pending.

## Cookie attributes and server requirements

Pending.

## The stale page case

Pending.

## Review findings

Pending.

## Rulings

Pending.

## Deploy notes for Mira

Pending.

## Proposed tasks

Pending.
