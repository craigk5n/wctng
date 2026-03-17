# CalDAV Client Setup Guide

WebCalendar supports CalDAV access at `/dav/` for native calendar app integration.

## Connection Details

| Setting | Value |
|---------|-------|
| Server URL | `http://your-server:47180/dav/` |
| Username | Your WebCalendar username |
| Password | Your WebCalendar password |
| Calendar Path | `/dav/calendars/{username}/default/` |
| Principal URL | `/dav/principals/{username}` |

## Apple Calendar (macOS / iOS)

### macOS
1. Open **System Settings** → **Internet Accounts** → **Add Account** → **Other** → **CalDAV Account**
2. Account Type: **Manual**
3. Server: `http://your-server:47180/dav/principals/admin`
4. Username: `admin`
5. Password: your password
6. Click **Sign In**

### iOS
1. **Settings** → **Calendar** → **Accounts** → **Add Account** → **Other** → **Add CalDAV Account**
2. Server: `your-server:47180/dav/principals/admin`
3. Username: `admin`
4. Password: your password

### Supported Features
- Events (VEVENT): create, edit, delete, recurring events
- Tasks/Reminders (VTODO): via Apple Reminders app
- Sync: automatic via ctag/sync-token

## Thunderbird (Lightning / built-in)

1. Open Thunderbird, go to **Calendar** tab
2. Right-click in calendar list → **New Calendar**
3. Select **On the Network**
4. Format: **CalDAV**
5. Location: `http://your-server:47180/dav/calendars/admin/default/`
6. Enter username and password when prompted

### Supported Features
- Events (VEVENT): full CRUD
- Tasks (VTODO): full CRUD via Tasks view
- Journals (VJOURNAL): not natively supported by Thunderbird
- Sync: automatic

## DAVx5 (Android)

1. Install **DAVx5** from F-Droid or Play Store
2. Add Account → **Login with URL and user name**
3. Base URL: `http://your-server:47180/dav/`
4. Username: `admin`
5. Password: your password
6. Select calendars and task lists to sync

### Supported Features
- Events: synced to Android Calendar app
- Tasks: synced to compatible task apps (OpenTasks, Tasks.org)
- Sync: configurable interval

## GNOME Calendar

1. Open **GNOME Settings** → **Online Accounts** → **Other** → **CalDAV**
2. URL: `http://your-server:47180/dav/calendars/admin/default/`
3. Username: `admin`
4. Password: your password

### Supported Features
- Events: view and create
- Tasks/Journals: not supported by GNOME Calendar (use GNOME To Do for tasks)

## Known Limitations

- **Single calendar per user**: WebCalendar has one implicit calendar per user. Creating additional calendars via CalDAV clients is acknowledged but maps to the same calendar.
- **Alarms/Reminders**: VALARM components are not stored or returned.
- **Attachments**: ATTACH properties are not supported.
- **Categories**: CalDAV CATEGORIES are not mapped to WebCalendar categories.
- **Attendees**: ATTENDEE/ORGANIZER properties are not fully mapped to WebCalendar participants.
- **Time zone handling**: Events are stored in server local time. VTIMEZONE components are parsed but not persisted.
- **CalDAV sharing**: Calendar sharing (RFC 7809) is not supported. Use WebCalendar's layer system instead.
- **Subscription calendars**: Webcal:// subscription URLs are not supported.

## Authentication

Both **HTTP Basic** and **Bearer JWT** authentication are supported:

- **Basic Auth**: Standard username/password (used by most CalDAV clients)
- **Bearer JWT**: Send `Authorization: Bearer {token}` header (useful for API clients)

In multi-tenant mode, the CalDAV server resolves the tenant from the subdomain or `X-Tenant-Id` header, same as the REST API.

## Troubleshooting

### "401 Unauthorized"
- Verify username and password
- Check that the user exists in WebCalendar

### "404 Not Found"
- Verify the server URL includes `/dav/`
- Check that nginx is configured to pass `/dav/` to PHP-FPM

### Events not syncing
- Check the server logs at `/var/log/nginx/error.log`
- Verify the calendar URL: `/dav/calendars/{username}/default/`
- Try a manual PROPFIND: `curl -u admin:admin -X PROPFIND http://localhost:47180/dav/`
