# BetLedger for Android

A small native shell around the BetLedger website, made to experiment with push
notifications (roadmap Phase 5, design in "Android app and push notifications" in
`docs/spec.md`).

- **Screens:** a full-screen WebView on the BetLedger server, so the app is the website's
  phone layout. Sign in inside the app. Links to other sites open in the browser.
- **Native:** Firebase Cloud Messaging (FCM). The app gets a push token and hands it to the
  page through the `window.BetLedgerAndroid` bridge; the page registers it with the server
  (`resources/js/push.js`). Data messages and foreground messages are shown by
  `PushMessagingService`; tapping a notification opens its page in the app.

| File | What it does |
| --- | --- |
| `MainActivity.kt` | WebView, edge-to-edge insets, back button, file picker, notification taps, permission prompt |
| `WebAppBridge.kt` | `window.BetLedgerAndroid.getPushToken()` / `getDeviceName()` |
| `PushTokens.kt` | Gets and stores the FCM token; tells the page when it changes |
| `PushMessagingService.kt` | `onNewToken` and `onMessageReceived` |
| `Notifications.kt` | The `general` channel and building notifications |

## Build and run

You need Android Studio (it brings the SDK and a JDK). The project uses Gradle 8.14.3,
Android Gradle Plugin 8.7.3, Kotlin 2.1.20, compile/target SDK 35, min SDK 26 (Android 8).
Android Studio may offer newer versions; upgrading is fine.

1. **Start the server** from the repository root:

   ```bash
   npm run build          # the emulator can't reach Vite's dev server: use built assets
   php artisan serve      # http://127.0.0.1:8000
   ```

   If `public/hot` exists (left by `npm run dev` / `composer run dev`), delete it or
   stop Vite, or the pages load scripts from `localhost:5173` and break in the app.

2. **Open `android/`** in Android Studio (File → Open) and let Gradle sync.

3. **Create an emulator with Google Play** (Device Manager → a phone with a "Google Play"
   system image). FCM needs Google Play services; plain "Google APIs" images don't
   always deliver pushes. Sign in to a Google account on the emulator if pushes don't
   arrive.

4. **Run** the `app` configuration. The emulator reaches your computer's
   `127.0.0.1:8000` as `http://10.0.2.2:8000`, the app's default.

Without Firebase set up the app still works as a WebView of BetLedger; push stays off
(Logcat says "Firebase is not configured").

### Push notifications

1. Do the Firebase setup in `docs/SETUP.md` ("Push notifications"): `FCM_CREDENTIALS` in
   the server's `.env`, and `google-services.json` for package `com.betledger.app` in
   `android/app/` (git-ignored). Rebuild the app after adding it.
2. Start the app, allow notifications, sign in.
3. In BetLedger: Settings → Notifications. Your phone is listed ("Last opened just now").
4. Press **Send test notification** or **Send data message**.

Things worth trying, and what to expect:

| App state | Notification message | Data message |
| --- | --- | --- |
| In the foreground | `onMessageReceived` runs; the app shows it | `onMessageReceived` runs; the app shows it |
| In the background | Android shows it (default channel and icon from the manifest); `onMessageReceived` does **not** run | `onMessageReceived` runs; the app shows it |
| Swiped away | Same as background | Same as background |
| Force stopped (Settings → Apps) | Nothing arrives until the app is opened again | Nothing arrives |

Tapping either kind opens Settings → Notifications in the app: the message's `url` comes
in as an intent extra (`MainActivity.notificationUrl`). Only its path is used, on the
app's own server.

Other experiments: deny the permission (Settings → Apps → BetLedger → Notifications) and
send again; clear the app's storage, so FCM issues a new token and the old one is removed
from the server on the next send (`UNREGISTERED`); log out in the app, which removes the
phone from Settings → Notifications.

### On a real phone

Run the server on your network and point the app at it in `android/local.properties`
(git-ignored):

```properties
betledger.url=http://192.168.1.20:8000
```

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

Debug builds allow plain HTTP; release builds need HTTPS. Or set `betledger.url` to the
production URL.

## Debugging

- Logcat, filtered on `PushTokens`, `FirebaseMessaging` or `Notifications`.
- Web page: `chrome://inspect` on your computer shows the app's WebView (console,
  network) for debug builds.
- Server: `storage/logs/laravel.log` has "FCM send failed" with Firebase's reason.

## Not done (yet)

- Downloads in the WebView (nothing in the app downloads files today).
- Settle reminders (5.3) and settling from the notification (5.4).
