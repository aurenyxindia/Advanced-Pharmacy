package `in`.paperlesspharmacy.advanced.ui.screens

import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.activity.ComponentActivity
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.Shield
import androidx.compose.material.icons.filled.VerifiedUser
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.google.gson.JsonObject
import `in`.paperlesspharmacy.advanced.R
import `in`.paperlesspharmacy.advanced.data.ApiException
import `in`.paperlesspharmacy.advanced.data.amount
import `in`.paperlesspharmacy.advanced.data.bool
import `in`.paperlesspharmacy.advanced.data.jInt
import `in`.paperlesspharmacy.advanced.data.str
import `in`.paperlesspharmacy.advanced.payments.RazorpayCheckoutLauncher
import `in`.paperlesspharmacy.advanced.payments.RazorpayPayResult
import `in`.paperlesspharmacy.advanced.ui.AppViewModel
import `in`.paperlesspharmacy.advanced.ui.components.ErrorBanner
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaMuted
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaSlate
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaTeal
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaTealDark
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.text.NumberFormat
import java.util.Locale


private val GstinRe = Regex("^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$")
private val PanRe = Regex("^[A-Z]{5}[0-9]{4}[A-Z]$")

private fun inr(value: Double): String =
    NumberFormat.getCurrencyInstance(Locale("en", "IN")).format(value)

@Composable
fun SplashScreen() {
    var visible by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { visible = true }
    val alpha by animateFloatAsState(if (visible) 1f else 0f, tween(700), label = "splash")
    Box(
        Modifier
            .fillMaxSize()
            .background(Brush.linearGradient(listOf(PharmaTealDark, PharmaTeal, Color(0xFF14B8A6)))),
        contentAlignment = Alignment.Center,
    ) {
        Column(
            Modifier.alpha(alpha).padding(28.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Surface(
                shape = RoundedCornerShape(16.dp),
                color = Color.White,
                shadowElevation = 10.dp,
                modifier = Modifier.fillMaxWidth(0.78f),
            ) {
                Image(
                    painter = painterResource(R.drawable.paperless_pharmacy_logo),
                    contentDescription = "Paperless Pharmacy",
                    contentScale = ContentScale.Fit,
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(horizontal = 18.dp, vertical = 16.dp)
                        .heightIn(max = 72.dp),
                )
            }
            Spacer(Modifier.height(16.dp))
            Text(
                "Advanced Pharmacy",
                color = Color.White,
                fontWeight = FontWeight.Bold,
                fontSize = 18.sp,
            )
            Text(
                "Billing to books — in your pocket",
                color = Color.White.copy(alpha = 0.85f),
                style = MaterialTheme.typography.labelMedium,
                textAlign = TextAlign.Center,
            )
        }
    }
}

private enum class PayUiStep { List, Review, Processing, Success }

@Composable
fun PlansScreen(
    vm: AppViewModel,
    title: String = "Choose a plan",
    subtitle: String = "Select a plan. Amount is locked and paid securely via Razorpay (UPI / cards / netbanking).",
    onBackToLogin: (() -> Unit)? = null,
    onActivated: () -> Unit,
    onRazorpayResult: MutableState<RazorpayPayResult?>? = null,
) {
    var step by remember { mutableStateOf(PayUiStep.List) }
    var loading by remember { mutableStateOf(true) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var plans by remember { mutableStateOf<List<JsonObject>>(emptyList()) }
    var selected by remember { mutableStateOf<JsonObject?>(null) }
    var checkoutPayload by remember { mutableStateOf<JsonObject?>(null) }
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val activity = context as? ComponentActivity

    fun load() {
        loading = true
        error = null
        scope.launch {
            runCatching {
                withContext(Dispatchers.IO) { vm.api.subscriptionPlans() }
            }.onSuccess {
                plans = it
                loading = false
            }.onFailure {
                error = it.message ?: "Could not load plans."
                loading = false
            }
        }
    }
    LaunchedEffect(Unit) { load() }

    fun startRazorpay(plan: JsonObject) {
        if (activity == null) {
            error = "Unable to open payment screen."
            return
        }
        busy = true
        error = null
        step = PayUiStep.Processing
        scope.launch {
            runCatching {
                withContext(Dispatchers.IO) {
                    vm.api.createSubscriptionCheckout(plan.str("code"))
                }
            }.onSuccess { data ->
                checkoutPayload = data
                runCatching {
                    RazorpayCheckoutLauncher.open(activity, data)
                    // Checkout is a separate activity; wait for PaymentResult callback.
                    step = PayUiStep.Review
                    busy = false
                }.onFailure {
                    error = it.message ?: "Could not open Razorpay Checkout."
                    step = PayUiStep.Review
                    busy = false
                }
            }.onFailure {
                error = (it as? ApiException)?.message ?: it.message ?: "Could not create payment order."
                step = PayUiStep.Review
                busy = false
            }
        }
    }

    fun verifyPayment(orderId: String, paymentId: String, signature: String) {
        busy = true
        error = null
        step = PayUiStep.Processing
        scope.launch {
            runCatching {
                withContext(Dispatchers.IO) {
                    vm.api.verifySubscriptionPayment(orderId, paymentId, signature)
                }
            }.onSuccess {
                step = PayUiStep.Success
                busy = false
                delay(1400)
                vm.markSubscriptionPaid()
                onActivated()
            }.onFailure {
                error = (it as? ApiException)?.message ?: it.message ?: "Payment verification failed."
                step = PayUiStep.Review
                busy = false
            }
        }
    }

    // Handle Checkout callback from MainActivity
    LaunchedEffect(onRazorpayResult?.value) {
        val result = onRazorpayResult?.value ?: return@LaunchedEffect
        onRazorpayResult.value = null
        when (result) {
            is RazorpayPayResult.Success -> verifyPayment(result.orderId, result.paymentId, result.signature)
            is RazorpayPayResult.Failed -> {
                busy = false
                error = result.message.ifBlank { "Payment cancelled or failed." }
                step = if (selected != null) PayUiStep.Review else PayUiStep.List
            }
        }
    }

    when (step) {
        PayUiStep.Success -> PaymentSuccessScreen()
        PayUiStep.Processing -> PaymentProcessingScreen(
            message = if (checkoutPayload == null) "Creating secure payment order…" else "Confirming payment…",
        )
        PayUiStep.Review -> {
            val plan = selected
            if (plan == null) {
                step = PayUiStep.List
            } else {
                CheckoutReviewScreen(
                    plan = plan,
                    busy = busy,
                    error = error,
                    onClearError = { error = null },
                    onBack = {
                        if (!busy) {
                            selected = null
                            checkoutPayload = null
                            error = null
                            step = PayUiStep.List
                        }
                    },
                    onPay = { startRazorpay(plan) },
                )
            }
        }
        PayUiStep.List -> GuestScaffold {
            Text(title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, color = PharmaSlate)
            Text(subtitle, style = MaterialTheme.typography.bodySmall, color = PharmaMuted)
            TrustRow()
            error?.let { ErrorBanner(it) { load() } }
            if (loading) {
                Box(Modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(Modifier.size(22.dp), color = PharmaTeal, strokeWidth = 2.dp)
                }
            } else {
                plans.forEach { plan ->
                    PlanCard(plan, selected = selected?.str("code") == plan.str("code")) {
                        selected = plan
                        error = null
                        step = PayUiStep.Review
                    }
                }
                if (plans.isEmpty() && error == null) {
                    Text("No plans available.", style = MaterialTheme.typography.bodySmall, color = PharmaMuted)
                }
            }
            onBackToLogin?.let { back ->
                TextButton(onClick = back, modifier = Modifier.fillMaxWidth()) {
                    Text("Back to sign in", color = PharmaTeal, fontWeight = FontWeight.SemiBold)
                }
            }
        }
    }
}

@Composable
private fun TrustRow() {
    Row(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(10.dp))
            .background(Color(0xFFF0FDFA))
            .padding(horizontal = 10.dp, vertical = 8.dp),
        horizontalArrangement = Arrangement.spacedBy(10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Default.VerifiedUser, null, tint = PharmaTeal, modifier = Modifier.size(18.dp))
        Column(Modifier.weight(1f)) {
            Text("Secured by Razorpay", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, color = PharmaSlate)
            Text("UPI · Cards · Netbanking · Amount auto-filled", style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
        }
        Icon(Icons.Default.Lock, null, tint = PharmaTealDark, modifier = Modifier.size(16.dp))
    }
}

@Composable
private fun CheckoutReviewScreen(
    plan: JsonObject,
    busy: Boolean,
    error: String?,
    onClearError: () -> Unit,
    onBack: () -> Unit,
    onPay: () -> Unit,
) {
    val payable = plan.amount("payable")
    GuestScaffold {
        TextButton(onClick = onBack, enabled = !busy) {
            Icon(Icons.AutoMirrored.Filled.ArrowBack, null, Modifier.size(16.dp), tint = PharmaTeal)
            Spacer(Modifier.width(4.dp))
            Text("Change plan", color = PharmaTeal)
        }
        Text("Confirm & pay", fontWeight = FontWeight.Bold, color = PharmaSlate, style = MaterialTheme.typography.titleMedium)
        Text(
            "You will be redirected to Razorpay. If you choose UPI, your UPI app opens with the amount already filled.",
            style = MaterialTheme.typography.bodySmall,
            color = PharmaMuted,
        )
        error?.let { ErrorBanner(it, onClearError) }

        Column(
            Modifier
                .fillMaxWidth()
                .clip(RoundedCornerShape(12.dp))
                .border(1.dp, Color(0xFFE2E8F0), RoundedCornerShape(12.dp))
                .padding(14.dp),
            verticalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            Text(plan.str("name"), fontWeight = FontWeight.SemiBold, color = PharmaSlate)
            PlanPriceBreakdown(plan)
            HorizontalDivider(color = Color(0xFFE2E8F0))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                Text("Amount payable", fontWeight = FontWeight.SemiBold, color = PharmaSlate)
                Text(inr(payable), fontWeight = FontWeight.Bold, color = PharmaTealDark, fontSize = 18.sp)
            }
        }

        TrustRow()
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Default.Shield, null, Modifier.size(16.dp), tint = Color(0xFF047857))
            Text(
                "We never ask you to type a UPI reference. Activation happens only after Razorpay confirms payment.",
                style = MaterialTheme.typography.labelSmall,
                color = PharmaMuted,
                modifier = Modifier.weight(1f),
            )
        }

        Button(
            onClick = onPay,
            enabled = !busy,
            colors = ButtonDefaults.buttonColors(containerColor = PharmaTeal, contentColor = Color.White),
            modifier = Modifier.fillMaxWidth().height(48.dp),
            shape = RoundedCornerShape(12.dp),
        ) {
            if (busy) {
                CircularProgressIndicator(Modifier.size(18.dp), color = Color.White, strokeWidth = 2.dp)
                Spacer(Modifier.width(8.dp))
                Text("Opening Razorpay…")
            } else {
                Icon(Icons.Default.Lock, null, Modifier.size(18.dp))
                Spacer(Modifier.width(8.dp))
                Text("Pay ${inr(payable)} securely", fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

@Composable
private fun PlanPriceBreakdown(plan: JsonObject) {
    val lifetime = plan.bool("is_lifetime")
    val months = plan.jInt("months")
    if (!lifetime && months > 0) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            Text("Base (${months} mo)", style = MaterialTheme.typography.labelMedium, color = PharmaMuted)
            Text(inr(plan.amount("base_amount")), style = MaterialTheme.typography.labelMedium, color = PharmaSlate)
        }
    } else if (lifetime) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            Text("Lifetime base", style = MaterialTheme.typography.labelMedium, color = PharmaMuted)
            Text(inr(plan.amount("lifetime_base").takeIf { it > 0 } ?: plan.amount("base_amount")), style = MaterialTheme.typography.labelMedium, color = PharmaSlate)
        }
    }
    if (plan.amount("discount_amount") > 0) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            Text("Discount", style = MaterialTheme.typography.labelMedium, color = Color(0xFF047857))
            Text("-${inr(plan.amount("discount_amount"))}", style = MaterialTheme.typography.labelMedium, color = Color(0xFF047857))
        }
    }
    if (plan.amount("gst_amount") > 0) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            Text("GST", style = MaterialTheme.typography.labelMedium, color = PharmaMuted)
            Text(inr(plan.amount("gst_amount")), style = MaterialTheme.typography.labelMedium, color = PharmaSlate)
        }
    }
}

@Composable
private fun PaymentProcessingScreen(message: String) {
    Box(
        Modifier
            .fillMaxSize()
            .background(Brush.linearGradient(listOf(PharmaTealDark, PharmaTeal, Color(0xFF14B8A6)))),
        contentAlignment = Alignment.Center,
    ) {
        Column(
            Modifier
                .padding(24.dp)
                .clip(RoundedCornerShape(18.dp))
                .background(Color.White)
                .padding(28.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            CircularProgressIndicator(color = PharmaTeal, strokeWidth = 3.dp)
            Text("Please wait", fontWeight = FontWeight.Bold, color = PharmaSlate)
            Text(message, style = MaterialTheme.typography.bodySmall, color = PharmaMuted, textAlign = TextAlign.Center)
            Text(
                "Do not press back while payment is in progress.",
                style = MaterialTheme.typography.labelSmall,
                color = PharmaMuted,
                textAlign = TextAlign.Center,
            )
        }
    }
}

@Composable
private fun PaymentSuccessScreen() {
    Box(
        Modifier
            .fillMaxSize()
            .background(Brush.linearGradient(listOf(PharmaTealDark, PharmaTeal, Color(0xFF14B8A6)))),
        contentAlignment = Alignment.Center,
    ) {
        Column(
            Modifier
                .padding(24.dp)
                .clip(RoundedCornerShape(18.dp))
                .background(Color.White)
                .padding(28.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Icon(Icons.Default.CheckCircle, null, tint = Color(0xFF059669), modifier = Modifier.size(52.dp))
            Text("Payment successful", fontWeight = FontWeight.Bold, color = PharmaSlate, fontSize = 18.sp)
            Text(
                "Your subscription is active. Opening Advanced Pharmacy…",
                style = MaterialTheme.typography.bodySmall,
                color = PharmaMuted,
                textAlign = TextAlign.Center,
            )
        }
    }
}

@Composable
private fun PlanCard(plan: JsonObject, selected: Boolean = false, onSelect: () -> Unit) {
    val payable = plan.amount("payable")
    val discount = plan.amount("discount_pct")
    val gst = plan.amount("gst_pct")
    val lifetime = plan.bool("is_lifetime")
    val months = plan.jInt("months")
    val subtitle = when {
        lifetime -> "One-time · GST ${gst.strip()}%"
        months == 1 -> "₹${plan.amount("monthly_rate").strip()}/month"
        else -> "₹${plan.amount("monthly_rate").strip()} × $months months" +
            if (discount > 0) " · ${discount.strip()}% off" else ""
    }
    val border = if (selected) PharmaTeal else Color(0xFFE2E8F0)
    Column(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(12.dp))
            .border(if (selected) 2.dp else 1.dp, border, RoundedCornerShape(12.dp))
            .background(if (selected) Color(0xFFF0FDFA) else Color.White)
            .clickable(onClick = onSelect)
            .padding(12.dp),
        verticalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(
                plan.str("name").ifBlank { plan.str("code") },
                modifier = Modifier.weight(1f),
                fontWeight = FontWeight.SemiBold,
                color = PharmaSlate,
                style = MaterialTheme.typography.titleSmall,
            )
            Text(inr(payable), fontWeight = FontWeight.Bold, color = PharmaTealDark)
        }
        Text(subtitle, style = MaterialTheme.typography.labelSmall, color = PharmaMuted)
        if (plan.amount("discount_amount") > 0) {
            Text(
                "You save ${inr(plan.amount("discount_amount"))}",
                style = MaterialTheme.typography.labelSmall,
                color = Color(0xFF047857),
            )
        }
        Text("Select plan", style = MaterialTheme.typography.labelMedium, color = PharmaTeal, fontWeight = FontWeight.SemiBold)
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun RegisterScreen(
    vm: AppViewModel,
    onBack: () -> Unit,
    onDone: (String) -> Unit,
) {
    var businessType by remember { mutableStateOf("retail") }
    var businessName by remember { mutableStateOf("") }
    var ownerName by remember { mutableStateOf("") }
    var email by remember { mutableStateOf("") }
    var phone by remember { mutableStateOf("") }
    var license by remember { mutableStateOf("") }
    var gstin by remember { mutableStateOf("") }
    var pan by remember { mutableStateOf("") }
    var fssai by remember { mutableStateOf("") }
    var address by remember { mutableStateOf("") }
    var city by remember { mutableStateOf("") }
    var state by remember { mutableStateOf("") }
    var pincode by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var confirm by remember { mutableStateOf("") }
    var showPassword by remember { mutableStateOf(false) }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var typeOpen by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val gstRequired = businessType == "wholesale" || businessType == "both"

    fun submit() {
        val gst = gstin.trim().uppercase()
        val panVal = pan.trim().uppercase()
        val err = when {
            businessName.trim().length < 3 -> "Business name must be at least 3 characters."
            ownerName.trim().length < 3 -> "Owner name must be at least 3 characters."
            !email.trim().contains('@') -> "Enter a valid email address."
            phone.filter { it.isDigit() }.let { it.length != 10 || it.first() !in '6'..'9' } ->
                "Enter a valid 10-digit mobile starting with 6–9."
            license.trim().length < 3 -> "Drug license number is required."
            gstRequired && gst.isBlank() -> "GSTIN is required for wholesale / both."
            gst.isNotBlank() && !GstinRe.matches(gst) -> "Enter a valid 15-character GSTIN."
            panVal.isNotBlank() && !PanRe.matches(panVal) -> "Enter a valid PAN (ABCDE1234F)."
            fssai.isNotBlank() && (fssai.length != 14 || fssai.any { !it.isDigit() }) ->
                "FSSAI must be 14 digits."
            pincode.isNotBlank() && pincode.length != 6 -> "Pincode must be 6 digits."
            password.length < 8 -> "Password must be at least 8 characters."
            password != confirm -> "Password confirmation does not match."
            else -> null
        }
        if (err != null) {
            error = err
            return
        }
        loading = true
        error = null
        scope.launch {
            runCatching {
                withContext(Dispatchers.IO) {
                    val body = JsonObject().apply {
                        addProperty("business_type", businessType)
                        addProperty("business_name", businessName.trim())
                        addProperty("owner_name", ownerName.trim())
                        addProperty("email", email.trim().lowercase())
                        addProperty("phone", phone.filter { it.isDigit() })
                        addProperty("drug_license_no", license.trim())
                        if (gst.isNotBlank()) addProperty("gstin", gst)
                        if (panVal.isNotBlank()) addProperty("pan", panVal)
                        if (fssai.isNotBlank()) addProperty("fssai_no", fssai)
                        if (address.isNotBlank()) addProperty("address", address.trim())
                        if (city.isNotBlank()) addProperty("city", city.trim())
                        if (state.isNotBlank()) addProperty("state", state.trim())
                        if (pincode.isNotBlank()) addProperty("pincode", pincode)
                        addProperty("password", password)
                        addProperty("password_confirmation", confirm)
                        addProperty("access_channel", "android")
                    }
                    vm.api.registerPharmacy(body)
                }
            }.onSuccess { root ->
                onDone(
                    root.str("message").ifBlank {
                        "Registration successful. Your 3-day free trial has started — you can sign in now."
                    },
                )
            }.onFailure {
                error = (it as? ApiException)?.message ?: it.message ?: "Registration failed."
            }
            loading = false
        }
    }

    GuestScaffold {
        TextButton(onClick = onBack, enabled = !loading) {
            Icon(Icons.AutoMirrored.Filled.ArrowBack, null, Modifier.size(16.dp), tint = PharmaTeal)
            Spacer(Modifier.width(4.dp))
            Text("Back to sign in", color = PharmaTeal)
        }
        Text("Register pharmacy", fontWeight = FontWeight.Bold, color = PharmaSlate)
        Text(
            "3-day free trial starts after registration. Choose a paid plan anytime.",
            style = MaterialTheme.typography.bodySmall,
            color = PharmaTealDark,
            fontWeight = FontWeight.SemiBold,
        )
        error?.let { ErrorBanner(it) { error = null } }

        ExposedDropdownMenuBox(expanded = typeOpen, onExpandedChange = { typeOpen = !typeOpen }) {
            OutlinedTextField(
                value = when (businessType) {
                    "wholesale" -> "Wholesale / PCD"
                    "both" -> "Retail + Wholesale (Both)"
                    else -> "Retail pharmacy"
                },
                onValueChange = {},
                readOnly = true,
                label = { Text("Business type") },
                trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(typeOpen) },
                modifier = Modifier.menuAnchor().fillMaxWidth(),
                colors = guestFieldColors(),
            )
            ExposedDropdownMenu(expanded = typeOpen, onDismissRequest = { typeOpen = false }) {
                listOf(
                    "retail" to "Retail pharmacy",
                    "wholesale" to "Wholesale / PCD",
                    "both" to "Retail + Wholesale (Both)",
                ).forEach { (code, label) ->
                    DropdownMenuItem(text = { Text(label) }, onClick = {
                        businessType = code
                        typeOpen = false
                    })
                }
            }
        }
        GuestField("Business / Pharmacy name", businessName, { businessName = it })
        GuestField("Owner / Proprietor name", ownerName, { ownerName = it })
        GuestField("Drug license no.", license, { license = it })
        GuestField("Email", email, { email = it }, KeyboardType.Email)
        GuestField("Mobile", phone, { phone = it.filter(Char::isDigit).take(10) }, KeyboardType.Phone)
        GuestField(
            if (gstRequired) "GSTIN *" else "GSTIN",
            gstin,
            { gstin = it.uppercase().take(15) },
        )
        GuestField("PAN", pan, { pan = it.uppercase().take(10) })
        GuestField("FSSAI", fssai, { fssai = it.filter(Char::isDigit).take(14) }, KeyboardType.Number)
        GuestField("Address", address, { address = it })
        GuestField("City", city, { city = it })
        GuestField("State", state, { state = it })
        GuestField("Pincode", pincode, { pincode = it.filter(Char::isDigit).take(6) }, KeyboardType.Number)
        GuestField("Password", password, { password = it }, KeyboardType.Password, password = !showPassword)
        GuestField("Confirm password", confirm, { confirm = it }, KeyboardType.Password, password = true)
        Row(verticalAlignment = Alignment.CenterVertically) {
            Checkbox(checked = showPassword, onCheckedChange = { showPassword = it })
            Text("Show password", style = MaterialTheme.typography.labelMedium, color = PharmaMuted)
        }
        Button(
            onClick = { submit() },
            enabled = !loading,
            colors = ButtonDefaults.buttonColors(containerColor = PharmaTeal, contentColor = Color.White),
            modifier = Modifier.fillMaxWidth().height(46.dp),
            shape = RoundedCornerShape(10.dp),
        ) {
            if (loading) {
                CircularProgressIndicator(Modifier.size(18.dp), color = Color.White, strokeWidth = 2.dp)
                Spacer(Modifier.width(8.dp))
                Text("Submitting…")
            } else {
                Icon(Icons.Default.CheckCircle, null, Modifier.size(18.dp))
                Spacer(Modifier.width(8.dp))
                Text("Submit registration", fontWeight = FontWeight.SemiBold)
            }
        }
        Text(
            "Android-only account. You can sign in immediately for 3 trial days, then pick a subscription plan.",
            style = MaterialTheme.typography.labelSmall,
            color = PharmaMuted,
        )
    }
}

@Composable
private fun GuestScaffold(content: @Composable ColumnScope.() -> Unit) {
    Box(
        Modifier
            .fillMaxSize()
            .background(Brush.linearGradient(listOf(PharmaTealDark, PharmaTeal, Color(0xFF14B8A6)))),
    ) {
        Column(
            Modifier
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .systemBarsPadding()
                .imePadding()
                .padding(18.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Surface(
                shape = RoundedCornerShape(14.dp),
                color = Color.White,
                shadowElevation = 8.dp,
                modifier = Modifier.fillMaxWidth(0.78f),
            ) {
                Image(
                    painter = painterResource(R.drawable.paperless_pharmacy_logo),
                    contentDescription = "Paperless Pharmacy",
                    contentScale = ContentScale.Fit,
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(horizontal = 16.dp, vertical = 10.dp)
                        .heightIn(max = 48.dp),
                )
            }
            Spacer(Modifier.height(14.dp))
            Column(
                Modifier
                    .widthIn(max = 460.dp)
                    .fillMaxWidth()
                    .clip(RoundedCornerShape(18.dp))
                    .background(Color.White)
                    .padding(16.dp),
                verticalArrangement = Arrangement.spacedBy(8.dp),
                content = content,
            )
            Spacer(Modifier.height(12.dp))
            Text(
                "© Aurenyx Pharmatech Pvt. Ltd.",
                style = MaterialTheme.typography.labelSmall,
                color = Color.White.copy(alpha = 0.8f),
            )
        }
    }
}

@Composable
private fun GuestField(
    label: String,
    value: String,
    onChange: (String) -> Unit,
    keyboard: KeyboardType = KeyboardType.Text,
    password: Boolean = false,
) {
    OutlinedTextField(
        value = value,
        onValueChange = onChange,
        label = { Text(label) },
        singleLine = true,
        visualTransformation = if (password) PasswordVisualTransformation() else VisualTransformation.None,
        keyboardOptions = KeyboardOptions(keyboardType = keyboard),
        shape = RoundedCornerShape(10.dp),
        colors = guestFieldColors(),
        modifier = Modifier.fillMaxWidth(),
    )
}

@Composable
private fun guestFieldColors() = OutlinedTextFieldDefaults.colors(
    focusedBorderColor = PharmaTeal,
    unfocusedBorderColor = Color(0xFFCBD5E1),
    focusedLabelColor = PharmaTeal,
    cursorColor = PharmaTeal,
    focusedTextColor = PharmaSlate,
    unfocusedTextColor = PharmaSlate,
)

private fun Double.strip(): String =
    if (this % 1.0 == 0.0) this.toInt().toString() else String.format(Locale.US, "%.2f", this)
