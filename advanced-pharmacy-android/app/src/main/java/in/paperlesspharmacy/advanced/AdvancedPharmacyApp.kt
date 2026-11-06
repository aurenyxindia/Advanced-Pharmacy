package `in`.paperlesspharmacy.advanced

import android.app.Application
import com.razorpay.Checkout
import `in`.paperlesspharmacy.advanced.data.SessionStore
import `in`.paperlesspharmacy.advanced.data.master.MasterConfigDto
import `in`.paperlesspharmacy.advanced.data.master.MasterConfigLoader

class AdvancedPharmacyApp : Application() {
    lateinit var sessionStore: SessionStore
        private set

    lateinit var masterConfigs: Map<String, MasterConfigDto>
        private set

    override fun onCreate() {
        super.onCreate()
        sessionStore = SessionStore(this)
        masterConfigs = MasterConfigLoader.loadMasters(this)
        Checkout.preload(applicationContext)
    }
}
