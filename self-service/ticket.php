<?php
/**
 * Self-Service Portal — single ticket.
 *
 * Superseded by tickets.php, the two-pane My Tickets view: the list stays on
 * screen and the conversation loads beside it. This file remains ONLY as a
 * redirect, because ?id= links are already out in the wild — the dashboard used
 * them and people bookmark and share them.
 *
 * No auth guard here on purpose: it only forwards, and tickets.php does the
 * checking. Guarding here as well would mean starting a session just to decide
 * where to send someone, and an unauthenticated visitor lands on the login page
 * either way — with the ticket id preserved, so they arrive where they meant to.
 */
$ticketId = (int)($_GET['id'] ?? 0);
header('Location: tickets.php' . ($ticketId ? '?id=' . $ticketId : ''), true, 302);
exit;
