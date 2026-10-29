package com.betledger.app

import android.content.Context
import android.util.Log
import androidx.core.content.edit
import com.google.firebase.FirebaseApp
import com.google.firebase.messaging.FirebaseMessaging

/**
 * The device's Firebase Cloud Messaging token, kept in preferences so the web page can
 * read it synchronously through [WebAppBridge].
 */
object PushTokens {
    private const val TAG = "PushTokens"
    private const val PREFERENCES = "push"
    private const val KEY_TOKEN = "token"

    /** Called (on any thread) when the token changes, so the open page can register it. */
    @Volatile
    var onChanged: (() -> Unit)? = null

    fun current(context: Context): String =
        preferences(context).getString(KEY_TOKEN, null).orEmpty()

    fun save(context: Context, token: String) {
        if (token == current(context)) {
            return
        }

        preferences(context).edit { putString(KEY_TOKEN, token) }
        onChanged?.invoke()
    }

    /**
     * Ask Firebase for the token; it creates one on the first start. Without
     * google-services.json there is no Firebase, and the app runs without push.
     */
    fun refresh(context: Context) {
        val appContext = context.applicationContext

        if (FirebaseApp.getApps(appContext).isEmpty()) {
            Log.i(TAG, "Firebase is not configured (no google-services.json): push notifications are off.")
            return
        }

        FirebaseMessaging.getInstance().token
            .addOnSuccessListener { token -> save(appContext, token) }
            .addOnFailureListener { exception -> Log.w(TAG, "Could not get the FCM token.", exception) }
    }

    private fun preferences(context: Context) =
        context.applicationContext.getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE)
}
