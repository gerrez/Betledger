package com.betledger.app

import android.app.Application

class BetLedgerApp : Application() {
    override fun onCreate() {
        super.onCreate()
        Notifications.createChannels(this)
    }
}
