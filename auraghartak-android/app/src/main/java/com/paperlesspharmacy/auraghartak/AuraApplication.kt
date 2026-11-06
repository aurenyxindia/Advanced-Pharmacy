package com.paperlesspharmacy.auraghartak

import android.app.Application
import com.paperlesspharmacy.auraghartak.data.CustomerSession

class AuraApplication : Application() {
    override fun onCreate() {
        super.onCreate()
        CustomerSession.init(this)
    }
}
