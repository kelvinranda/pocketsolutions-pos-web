<?php
/**
 * 2026-10-17 — Transactions that can run inside another one, and a stand-in signed-in user.
 *
 * Customer and account-payment functions open their own transaction. The desktop sync runs each pushed event in
 * one transaction (so the event and its "already processed" marker are saved together). Starting a second
 * transaction in MySQL would silently commit the first, so these helpers use a SAVEPOINT when a transaction is
 * already open (psTxOuterRun), and a normal transaction otherwise — web pages behave exactly as before.
 *
 * psActorRun() lets server code act for a named user (the cashier on a desktop till) where functions read the
 * signed-in user from the session.
 */

/** @internal nesting depth of psTxOuterRun */
function &psTxOuterDepth(): int
{
    static $depth = 0;
    return $depth;
}

/** Run $fn while the caller already holds an open transaction (nested psTx* calls use savepoints). */
function psTxOuterRun(callable $fn)
{
    $d = &psTxOuterDepth();
    $d++;
    try {
        return $fn();
    } finally {
        $d--;
    }
}

function psTxBegin(mysqli $conn): bool
{
    if (psTxOuterDepth() > 0) {
        return (bool)mysqli_query($conn, 'SAVEPOINT ps_inner');
    }
    return mysqli_begin_transaction($conn);
}

function psTxRollback(mysqli $conn): bool
{
    if (psTxOuterDepth() > 0) {
        return (bool)mysqli_query($conn, 'ROLLBACK TO SAVEPOINT ps_inner');
    }
    return mysqli_rollback($conn);
}

function psTxCommit(mysqli $conn): bool
{
    if (psTxOuterDepth() > 0) {
        return (bool)mysqli_query($conn, 'RELEASE SAVEPOINT ps_inner');
    }
    return mysqli_commit($conn);
}

/** @internal */
function &psActorSlot(): ?string
{
    static $actor = null;
    return $actor;
}

/** Run $fn as $email (used by functions that would otherwise read $_SESSION['logger']). */
function psActorRun(string $email, callable $fn)
{
    $slot = &psActorSlot();
    $prev = $slot;
    $slot = trim($email);
    try {
        return $fn();
    } finally {
        $slot = $prev;
    }
}

/** The stand-in user, or null when none is set. */
function psActorOverride(): ?string
{
    $v = psActorSlot();
    return ($v !== null && $v !== '') ? $v : null;
}
