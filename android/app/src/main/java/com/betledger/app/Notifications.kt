package com.betledger.app

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.util.Log
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat

/**
 * Notification channels and showing notifications the app builds itself (data messages,
 * and any message that arrives while the app is in the foreground).
 */
object Notifications {
    /** Must match `PushMessage::DEFAULT_CHANNEL` on the server. */
    const val CHANNEL_GENERAL = "general"

    /** The page to open when a notification is tapped; FCM puts data keys in the same extras. */
    const val EXTRA_URL = "url"

    fun createChannels(context: Context) {
        val channel = NotificationChannel(
            CHANNEL_GENERAL,
            context.getString(R.string.notification_channel_general_name),
            NotificationManager.IMPORTANCE_HIGH,
        ).apply {
            description = context.getString(R.string.notification_channel_general_description)
        }

        context.getSystemService(NotificationManager::class.java).createNotificationChannel(channel)
    }

    fun show(context: Context, title: String, body: String, url: String?, channelId: String) {
        if (!canNotify(context)) {
            return
        }

        val manager = NotificationManagerCompat.from(context)
        val channel = if (manager.getNotificationChannel(channelId) != null) channelId else CHANNEL_GENERAL
        val id = (System.currentTimeMillis() % Int.MAX_VALUE).toInt()

        val open = Intent(context, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP
            putExtra(EXTRA_URL, url)
        }
        val contentIntent = PendingIntent.getActivity(
            context,
            id,
            open,
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
        )

        val notification = NotificationCompat.Builder(context, channel)
            .setSmallIcon(R.drawable.ic_notification)
            .setColor(ContextCompat.getColor(context, R.color.accent))
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setAutoCancel(true)
            .setContentIntent(contentIntent)
            .build()

        try {
            manager.notify(id, notification)
        } catch (exception: SecurityException) {
            Log.w("Notifications", "Notification permission revoked before showing it.", exception)
        }
    }

    private fun canNotify(context: Context): Boolean {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) {
            return false
        }

        return NotificationManagerCompat.from(context).areNotificationsEnabled()
    }
}
