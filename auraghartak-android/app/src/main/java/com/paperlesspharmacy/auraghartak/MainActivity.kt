package com.paperlesspharmacy.auraghartak

import android.content.Intent
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.result.contract.ActivityResultContracts
import com.paperlesspharmacy.auraghartak.notify.AuraWorkScheduler
import com.paperlesspharmacy.auraghartak.notify.NotificationHelper
import androidx.activity.SystemBarStyle
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import com.paperlesspharmacy.auraghartak.ui.AuraApp
import com.paperlesspharmacy.auraghartak.ui.AuraTheme

class MainActivity : ComponentActivity() {
    companion object {
        const val EXTRA_OPEN_ORDERS = "open_orders_tab"
    }

    private val deepToken = mutableStateOf<String?>(null)
    val openOrdersTab = mutableStateOf(false)

    private val notifyPermission = registerForActivityResult(
        ActivityResultContracts.RequestPermission(),
    ) { _ -> }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        NotificationHelper.ensureChannels(this)
        AuraWorkScheduler.schedule(this)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            notifyPermission.launch(android.Manifest.permission.POST_NOTIFICATIONS)
        }
        enableEdgeToEdge(
            statusBarStyle = SystemBarStyle.dark(android.graphics.Color.parseColor("#0F766E")),
            navigationBarStyle = SystemBarStyle.light(android.graphics.Color.WHITE, android.graphics.Color.WHITE),
        )
        if (savedInstanceState == null) {
            deepToken.value = tokenOf(intent)
            openOrdersTab.value = intent?.getBooleanExtra(EXTRA_OPEN_ORDERS, false) == true
        }
        setContent {
            val openOrders by openOrdersTab
            AuraTheme {
                AuraApp(
                    startToken = deepToken.value,
                    openOrdersOnLaunch = openOrders,
                    onConsumedOpenOrders = { openOrdersTab.value = false },
                )
            }
        }
    }

    override fun onResume() {
        super.onResume()
        AuraWorkScheduler.runOrderSyncNow(this)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        deepToken.value = tokenOf(intent)
        if (intent.getBooleanExtra(EXTRA_OPEN_ORDERS, false)) {
            openOrdersTab.value = true
        }
    }

    private fun tokenOf(intent: Intent?): String? {
        val data = intent?.data ?: return null
        return data.getQueryParameter("token")?.takeIf { it.isNotBlank() }
    }
}
