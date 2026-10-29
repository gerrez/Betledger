package com.betledger.app

import android.Manifest
import android.annotation.SuppressLint
import android.content.ActivityNotFoundException
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.webkit.CookieManager
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.FrameLayout
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.activity.OnBackPressedCallback
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat

/**
 * The whole app: a full-screen WebView on the BetLedger website, plus the native parts a
 * website can't do (push token, notification permission, opening a tapped notification).
 */
class MainActivity : ComponentActivity() {
    private val siteUri: Uri = Uri.parse(BuildConfig.BETLEDGER_URL)

    private lateinit var webView: WebView

    private var fileChooserCallback: ValueCallback<Array<Uri>>? = null

    private val fileChooser = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
        fileChooserCallback?.onReceiveValue(WebChromeClient.FileChooserParams.parseResult(result.resultCode, result.data))
        fileChooserCallback = null
    }

    private val notificationPermission = registerForActivityResult(ActivityResultContracts.RequestPermission()) { }

    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)

        webView = WebView(this)
        val root = FrameLayout(this).apply { addView(webView) }
        setContentView(root)

        // Edge to edge: keep the page clear of the status bar, navigation bar and keyboard.
        ViewCompat.setOnApplyWindowInsetsListener(root) { view, insets ->
            val bars = insets.getInsets(WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.displayCutout())
            val keyboard = insets.getInsets(WindowInsetsCompat.Type.ime())
            view.setPadding(bars.left, bars.top, bars.right, maxOf(bars.bottom, keyboard.bottom))
            WindowInsetsCompat.CONSUMED
        }

        configureWebView()

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                if (webView.canGoBack()) {
                    webView.goBack()
                } else {
                    finish()
                }
            }
        })

        val notificationUrl = notificationUrl(intent)
        if (notificationUrl != null || savedInstanceState == null || webView.restoreState(savedInstanceState) == null) {
            webView.loadUrl(notificationUrl ?: siteUri.toString())
        }

        askForNotificationPermission()
        PushTokens.refresh(this)
    }

    /** A notification was tapped while the app was open. */
    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        notificationUrl(intent)?.let { webView.loadUrl(it) }
    }

    override fun onStart() {
        super.onStart()
        PushTokens.onChanged = {
            webView.post { webView.evaluateJavascript("window.dispatchEvent(new Event('betledger:push-token'))", null) }
        }
    }

    override fun onStop() {
        PushTokens.onChanged = null
        super.onStop()
    }

    override fun onPause() {
        // Keep the login when Android kills the app in the background.
        CookieManager.getInstance().flush()
        super.onPause()
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        webView.saveState(outState)
    }

    override fun onDestroy() {
        webView.destroy()
        super.onDestroy()
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun configureWebView() {
        webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            userAgentString = "$userAgentString BetLedgerAndroid/${BuildConfig.VERSION_NAME}"
        }

        webView.addJavascriptInterface(WebAppBridge(applicationContext), WebAppBridge.NAME)

        webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                if (isOwnSite(request.url)) {
                    return false
                }

                openInBrowser(request.url)
                return true
            }
        }

        webView.webChromeClient = object : WebChromeClient() {
            // File inputs (the Sportmarket CSV import) need a native picker.
            override fun onShowFileChooser(
                view: WebView,
                callback: ValueCallback<Array<Uri>>,
                params: FileChooserParams,
            ): Boolean {
                fileChooserCallback?.onReceiveValue(null)
                fileChooserCallback = callback

                // Any type: `accept=".csv"` is an extension, not a MIME type the picker knows,
                // and the server validates the file anyway.
                val pick = Intent(Intent.ACTION_GET_CONTENT).apply {
                    addCategory(Intent.CATEGORY_OPENABLE)
                    type = "*/*"
                    putExtra(Intent.EXTRA_ALLOW_MULTIPLE, params.mode == FileChooserParams.MODE_OPEN_MULTIPLE)
                }

                return try {
                    fileChooser.launch(Intent.createChooser(pick, params.title))
                    true
                } catch (exception: ActivityNotFoundException) {
                    fileChooserCallback = null
                    false
                }
            }
        }
    }

    /**
     * The page a tapped notification points to, on this app's server. Only the path and
     * query are used: the server may name itself differently (e.g. localhost vs 10.0.2.2).
     */
    private fun notificationUrl(intent: Intent?): String? {
        val url = intent?.getStringExtra(Notifications.EXTRA_URL)?.takeIf { it.isNotEmpty() } ?: return null
        val target = Uri.parse(url)

        return siteUri.buildUpon()
            .encodedPath(target.encodedPath)
            .encodedQuery(target.encodedQuery)
            .fragment(null)
            .build()
            .toString()
    }

    private fun isOwnSite(uri: Uri): Boolean =
        uri.scheme == siteUri.scheme && uri.host == siteUri.host && uri.port == siteUri.port

    private fun openInBrowser(uri: Uri) {
        try {
            startActivity(Intent(Intent.ACTION_VIEW, uri))
        } catch (exception: ActivityNotFoundException) {
            Toast.makeText(this, R.string.no_app_for_link, Toast.LENGTH_SHORT).show()
        }
    }

    private fun askForNotificationPermission() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) {
            notificationPermission.launch(Manifest.permission.POST_NOTIFICATIONS)
        }
    }
}
