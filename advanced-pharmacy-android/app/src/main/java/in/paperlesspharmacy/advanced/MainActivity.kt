package `in`.paperlesspharmacy.advanced

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.SystemBarStyle
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.runtime.*
import androidx.compose.ui.graphics.toArgb
import androidx.lifecycle.viewmodel.compose.viewModel
import com.razorpay.PaymentData
import com.razorpay.PaymentResultWithDataListener
import `in`.paperlesspharmacy.advanced.payments.RazorpayPayResult
import `in`.paperlesspharmacy.advanced.ui.AppShell
import `in`.paperlesspharmacy.advanced.ui.AppViewModel
import `in`.paperlesspharmacy.advanced.ui.components.LoadingBlock
import `in`.paperlesspharmacy.advanced.ui.screens.LoginScreen
import `in`.paperlesspharmacy.advanced.ui.screens.PlansScreen
import `in`.paperlesspharmacy.advanced.ui.screens.RegisterScreen
import `in`.paperlesspharmacy.advanced.ui.screens.SplashScreen
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaTealDark
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaTheme
import kotlinx.coroutines.delay
import org.json.JSONObject

class MainActivity : ComponentActivity(), PaymentResultWithDataListener {
    private val razorpayResultState = mutableStateOf<RazorpayPayResult?>(null)

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge(
            statusBarStyle = SystemBarStyle.dark(PharmaTealDark.toArgb()),
            navigationBarStyle = SystemBarStyle.light(
                android.graphics.Color.TRANSPARENT,
                android.graphics.Color.TRANSPARENT,
            ),
        )
        setContent {
            PharmaTheme {
                val vm: AppViewModel = viewModel()
                val user by vm.user.collectAsState()
                val booting by vm.booting.collectAsState()
                val needsPayment by vm.needsPayment.collectAsState()
                var splash by remember { mutableStateOf(true) }
                // login | register
                var guest by remember { mutableStateOf("login") }
                var registerNotice by remember { mutableStateOf<String?>(null) }
                var forcePaywall by remember { mutableStateOf(false) }
                val razorpayResult = razorpayResultState

                LaunchedEffect(Unit) {
                    delay(1400)
                    splash = false
                }

                when {
                    splash -> SplashScreen()
                    booting -> LoadingBlock("Starting…")
                    user != null && (needsPayment || forcePaywall) -> PlansScreen(
                        vm = vm,
                        title = if (needsPayment) "Subscription required" else "Choose a plan",
                        subtitle = if (needsPayment) {
                            "Your free trial has ended. Select a plan and pay securely to continue."
                        } else {
                            "Buy a plan anytime. After successful Razorpay payment your subscription activates immediately."
                        },
                        onBackToLogin = if (needsPayment) {
                            {
                                vm.logout {
                                    forcePaywall = false
                                    guest = "login"
                                }
                            }
                        } else {
                            { forcePaywall = false }
                        },
                        onActivated = {
                            forcePaywall = false
                            vm.afterSubscriptionActivated()
                        },
                        onRazorpayResult = razorpayResult,
                    )
                    user != null -> AppShell(
                        vm = vm,
                        onBuyPlan = { forcePaywall = true },
                    )
                    guest == "register" -> RegisterScreen(
                        vm = vm,
                        onBack = { guest = "login" },
                        onDone = { msg ->
                            registerNotice = msg
                            guest = "login"
                        },
                    )
                    else -> LoginScreen(
                        vm = vm,
                        onLoggedIn = { },
                        onRegister = { guest = "register" },
                        initialInfo = registerNotice,
                    )
                }
            }
        }
    }

    override fun onPaymentSuccess(razorpayPaymentId: String?, paymentData: PaymentData?) {
        val orderId = paymentData?.orderId?.trim().orEmpty()
        val paymentId = (paymentData?.paymentId ?: razorpayPaymentId)?.trim().orEmpty()
        val signature = paymentData?.signature?.trim().orEmpty()
        razorpayResultState.value = if (orderId.isNotBlank() && paymentId.isNotBlank() && signature.isNotBlank()) {
            RazorpayPayResult.Success(orderId, paymentId, signature)
        } else {
            RazorpayPayResult.Failed(
                code = -1,
                message = "Payment succeeded but confirmation data was incomplete. Contact support with payment id: ${paymentId.ifBlank { "unknown" }}",
            )
        }
    }

    override fun onPaymentError(code: Int, response: String?, paymentData: PaymentData?) {
        val message = parseRazorpayError(response) ?: "Payment cancelled or failed."
        razorpayResultState.value = RazorpayPayResult.Failed(code, message)
    }

    private fun parseRazorpayError(response: String?): String? {
        if (response.isNullOrBlank()) return null
        return runCatching {
            val obj = JSONObject(response)
            when {
                obj.has("error") -> {
                    val err = obj.get("error")
                    when (err) {
                        is JSONObject -> err.optString("description").ifBlank {
                            err.optString("reason").ifBlank { null }
                        }
                        else -> err.toString()
                    }
                }
                obj.has("description") -> obj.optString("description").ifBlank { null }
                else -> response.take(180)
            }
        }.getOrElse { response.take(180) }
    }
}
