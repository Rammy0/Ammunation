# X10hosting upload package

Upload the contents of `public_html` to your X10hosting account's `public_html` directory.

## Before uploading

1. Create a MySQL database and database user in the hosting control panel, then grant the user access to the database.
2. Edit `public_html/config/db.php` with the database host, database name, username, and password shown by the host.
3. Import the SQL files in `public_html/database` into the new database in this order:
   - `create_users.sql`
   - `create_catalog.sql`
   - `create_orders.sql`
   - `create_checkouts.sql` (legacy checkout tables; optional for the current site)
4. Upload the contents of `public_html` (including `.htaccess`) into the hosting account's `public_html` directory.
5. Visit the site's HTTPS URL and test registration, login, checkout, and admin access.

The database configuration contains placeholders and must be updated before the site can connect. Do not upload `.git` or share the database credentials.
