package com.betledger.app

import android.content.Context
import android.os.Build
import android.webkit.JavascriptInterface

/**
 * Exposed to the BetLedger page as `window.BetLedgerAndroid`; the page posts the token
 * to the server (resources/js/push.js). Only BetLedger's own pages load in the WebView.
 */
class WebAppBridge(private val context: Context) {
    @JavascriptInterface
    fun getPushToken(): String = PushTokens.current(context)

    @JavascriptInterface
    fun getDeviceName(): String {
        val manufacturer = Build.MANUFACTURER.replaceFirstChar { it.titlecase() }

        return if (Build.MODEL.startsWith(Build.MANUFACTURER, ignoreCase = true)) {
            Build.MODEL
        } else {
            "$manufacturer ${Build.MODEL}"
        }
    }

    companion object {
        const val NAME = "BetLedgerAndroid"
    }
}
