# Super admin player accounts

The existing Super Admin Console includes **Find and delete player accounts**. Search by email or Indian phone number (including +91). Results show the username, contacts, wallet and phone verification status; up to 50 matches are returned. Refine the search when necessary.

Select Delete permanently and type `DELETE username` for the exact selected account. This removes the player, wallet, transaction and betting history, linked sub-admin player records, referral commission records and verification codes in one transaction. Shared runtime JSON removes the player's objects while keeping other players' entries. Fantasy team children are removed by the existing database cascades. Previously issued player sessions stop working, and the email/phone can be registered again. A seated Teen Patti player must leave the table first. Operator accounts are not managed by this feature. Operator action audit logs remain.

Both `/api/admin/accounts/search` and `/api/admin/accounts/delete` require a super-admin session. The delete endpoint takes a numeric player id and exact confirmation, never a bulk search query. No schema migration is needed. Private hosting `.env` settings are preserved by deployment.

Validation: `php -d extension=pdo_pgsql php-backend/tests/account_management_test.php` uses a development database and rolls back its changes. `npx playwright test e2e/account-management.spec.js` checks desktop/mobile search, wrong-confirmation cancellation and exact-id deletion with a mocked API.
