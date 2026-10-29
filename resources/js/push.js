/**
 * Push notifications in the Android app. The app's WebView exposes a JavaScript bridge,
 * `window.BetLedgerAndroid`, with `getPushToken()` (the Firebase token, or '' while it
 * isn't known yet) and `getDeviceName()`. This registers the token with the server for
 * the signed-in user once per app start, and again when the app dispatches
 * `betledger:push-token` on `window` because the token changed. In a normal browser
 * there is no bridge and nothing happens.
 */
let registered = null;

function registerPushDevice() {
    const bridge = window.BetLedgerAndroid;
    // Only pages behind the login have the meta tag. After `wire:navigate` swaps the head,
    // the newest one carries the current CSRF token and user.
    const meta = Array.from(document.querySelectorAll('meta[name="push-device-registration"]')).at(-1);

    if (bridge === undefined || typeof bridge.getPushToken !== 'function' || meta === undefined) {
        return;
    }

    const token = bridge.getPushToken();
    const key = `${meta.dataset.user}:${token}`;

    if (!token || registered === key) {
        return;
    }

    registered = key;

    fetch(meta.content, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': meta.dataset.csrf,
        },
        body: JSON.stringify({
            token,
            platform: 'android',
            name: typeof bridge.getDeviceName === 'function' ? bridge.getDeviceName() : null,
        }),
    })
        .then((response) => {
            if (!response.ok) {
                registered = null;
            }
        })
        .catch(() => {
            registered = null;
        });
}

// `livewire:navigated` also fires on the first page load.
document.addEventListener('livewire:navigated', registerPushDevice);
window.addEventListener('betledger:push-token', registerPushDevice);
