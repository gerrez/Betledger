package com.betledger.app

import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage

class PushMessagingService : FirebaseMessagingService() {
    /** Firebase rotated the token (or created the first one): the page registers it again. */
    override fun onNewToken(token: String) {
        PushTokens.save(applicationContext, token)
    }

    /**
     * Data messages always arrive here. Notification messages only arrive here while the
     * app is in the foreground; in the background Android shows them itself, and tapping
     * one opens [MainActivity] with the message's data (including `url`) as extras.
     */
    override fun onMessageReceived(message: RemoteMessage) {
        val data = message.data
        val title = message.notification?.title ?: data["title"] ?: return
        val body = message.notification?.body ?: data["body"].orEmpty()
        val channel = message.notification?.channelId ?: data["channel"] ?: Notifications.CHANNEL_GENERAL

        Notifications.show(applicationContext, title, body, data["url"]?.takeIf { it.isNotEmpty() }, channel)
    }
}
