package `in`.paperlesspharmacy.advanced.ui.screens

import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.Login
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material.icons.filled.Badge
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.HeadsetMic
import androidx.compose.material.icons.filled.Key
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material.icons.filled.VisibilityOff
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import `in`.paperlesspharmacy.advanced.R
import `in`.paperlesspharmacy.advanced.data.ApiException
import `in`.paperlesspharmacy.advanced.data.obj
import `in`.paperlesspharmacy.advanced.data.str
import `in`.paperlesspharmacy.advanced.ui.AppViewModel
import `in`.paperlesspharmacy.advanced.ui.components.ErrorBanner
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaMuted
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaSlate
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaTeal
import `in`.paperlesspharmacy.advanced.ui.theme.PharmaTealDark
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

private const val SUPPORT_PHONE = "8181000550"

private fun normalizeLoginId(value: String): String {
    val raw = value.trim()
    return if (raw.contains('@')) raw.lowercase() else raw.filter { it.isDigit() }
}

/** Digits-only typing → max 10; leave email local-part alone (web parity). */
private fun normalizeUsernameTyping(raw: String): String {
    val t = raw.trim()
    if (t.contains('@') || t.isEmpty() || t.any { it.isLetter() }) return raw.take(150)
    return t.filter { it.isDigit() }.take(10)
}

private fun usernameHint(username: String, touched: Boolean): String? {
    if (!touched) return null
    val n = normalizeLoginId(username)
    if (n.isBlank()) return "Email or mobile number is required."
    return when {
        username.contains('@') && !Regex("""^[^\s@]+@[^\s@]+\.[^\s@]+$""").matches(n) ->
            "Enter a valid email address."
        !username.contains('@') && (n.length != 10 || n.firstOrNull() !in '6'..'9') ->
            "Enter a valid 10-digit mobile number starting with 6–9."
        else -> null
    }
}

@Composable
fun LoginScreen(
    vm: AppViewModel,
    onLoggedIn: () -> Unit,
    onRegister: () -> Unit = {},
    initialInfo: String? = null,
) {
    var mode by remember { mutableStateOf("login") } // login | forgot
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var showPassword by remember { mutableStateOf(false) }
    var loading by remember { mutableStateOf(false) }
    var otpSent by remember { mutableStateOf(false) }
    var forgotUser by remember { mutableStateOf("") }
    var forgotPassword by remember { mutableStateOf("") }
    var showNewPassword by remember { mutableStateOf(false) }
    var otp by remember { mutableStateOf("") }
    var forgotTouched by remember { mutableStateOf(false) }
    var infoMessage by remember { mutableStateOf(initialInfo) }
    LaunchedEffect(initialInfo) {
        if (!initialInfo.isNullOrBlank()) infoMessage = initialInfo
    }
    var localError by remember { mutableStateOf<String?>(null) }
    val msg by vm.message.collectAsState()
    val focusManager = LocalFocusManager.current
    val uriHandler = LocalUriHandler.current
    val scope = rememberCoroutineScope()

    val canSubmit = !loading && email.isNotBlank() && password.isNotBlank()
    fun submit() {
        if (!canSubmit) return
        focusManager.clearFocus()
        localError = null
        infoMessage = null
        loading = true
        vm.login(email.trim(), password) { ok ->
            loading = false
            if (ok) onLoggedIn()
        }
    }

    fun openForgot() {
        mode = "forgot"
        otpSent = false
        forgotUser = email.trim()
        forgotPassword = ""
        otp = ""
        forgotTouched = false
        localError = null
        infoMessage = null
        vm.clearMessage()
    }

    fun backToLogin() {
        mode = "login"
        otpSent = false
        localError = null
        infoMessage = null
        vm.clearMessage()
    }

    fun sendOtp() {
        forgotTouched = true
        val hint = usernameHint(forgotUser, true)
        if (hint != null) {
            localError = hint
            return
        }
        val username = normalizeLoginId(forgotUser)
        focusManager.clearFocus()
        loading = true
        localError = null
        infoMessage = null
        vm.clearMessage()
        scope.launch {
            runCatching {
                withContext(Dispatchers.IO) { vm.api.sendForgotPasswordOtp(username) }
            }.onSuccess { root ->
                otpSent = true
                val notice = root.obj("data").str("notice")
                val base = root.str("message").ifBlank { "OTP sent." }
                infoMessage = if (notice.isNotBlank()) "$base $notice" else base
            }.onFailure {
                localError = (it as? ApiException)?.message ?: it.message ?: "Could not send OTP. Please try again."
            }
            loading = false
        }
    }

    fun resetPassword() {
        forgotTouched = true
        val hint = usernameHint(forgotUser, true)
        when {
            hint != null -> localError = hint
            forgotPassword.length < 6 -> localError = "Password must be at least 6 characters."
            otp.isBlank() -> localError = "OTP is required."
            else -> {
                val username = normalizeLoginId(forgotUser)
                focusManager.clearFocus()
                loading = true
                localError = null
                infoMessage = null
                vm.clearMessage()
                scope.launch {
                    runCatching {
                        withContext(Dispatchers.IO) {
                            vm.api.resetForgotPassword(username, otp.trim(), forgotPassword)
                        }
                    }.onSuccess { root ->
                        infoMessage = root.str("message").ifBlank { "Password changed successfully." }
                        mode = "login"
                        otpSent = false
                        if (forgotUser.contains('@')) email = forgotUser.trim()
                        password = ""
                        forgotPassword = ""
                        otp = ""
                    }.onFailure {
                        localError = (it as? ApiException)?.message
                            ?: it.message
                            ?: "Invalid OTP or could not reset password."
                    }
                    loading = false
                }
            }
        }
    }

    BoxWithConstraints(
        Modifier
            .fillMaxSize()
            .background(
                Brush.linearGradient(
                    listOf(PharmaTealDark, PharmaTeal, Color(0xFF14B8A6)),
                ),
            ),
    ) {
        Box(
            Modifier
                .size(220.dp)
                .offset(x = (-70).dp, y = (-60).dp)
                .clip(CircleShape)
                .background(Color.White.copy(alpha = 0.08f)),
        )
        Box(
            Modifier
                .size(260.dp)
                .align(Alignment.BottomEnd)
                .offset(x = 90.dp, y = 80.dp)
                .clip(CircleShape)
                .background(Color.White.copy(alpha = 0.07f)),
        )

        Column(
            Modifier
                .fillMaxWidth()
                .heightIn(min = maxHeight)
                .verticalScroll(rememberScrollState())
                .systemBarsPadding()
                .imePadding()
                .padding(horizontal = 18.dp, vertical = 20.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.Center,
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
                        .padding(horizontal = 16.dp, vertical = 12.dp)
                        .heightIn(max = 56.dp),
                )
            }
            Spacer(Modifier.height(10.dp))
            Text(
                "Run your pharmacy smarter — billing to books",
                style = MaterialTheme.typography.labelMedium,
                color = Color.White.copy(alpha = 0.85f),
                textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(16.dp))

            Column(
                Modifier
                    .widthIn(max = 420.dp)
                    .fillMaxWidth()
                    .shadow(16.dp, RoundedCornerShape(18.dp))
                    .clip(RoundedCornerShape(18.dp))
                    .background(Color.White)
                    .padding(horizontal = 18.dp, vertical = 20.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                if (mode == "login") {
                    Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
                        Text(
                            "Welcome back",
                            style = MaterialTheme.typography.titleMedium,
                            fontWeight = FontWeight.Bold,
                            color = PharmaSlate,
                        )
                        Text(
                            "Sign in to your pharmacy dashboard",
                            style = MaterialTheme.typography.bodySmall,
                            color = PharmaMuted,
                        )
                    }
                } else {
                    Column(Modifier.fillMaxWidth()) {
                        Text(
                            "Change Password",
                            style = MaterialTheme.typography.titleMedium,
                            fontWeight = FontWeight.Bold,
                            color = PharmaSlate,
                        )
                        Text(
                            "Reset your password using OTP sent to your registered email",
                            style = MaterialTheme.typography.bodySmall,
                            color = PharmaMuted,
                        )
                    }
                }

                infoMessage?.let { InfoBanner(it) { infoMessage = null } }
                localError?.let { ErrorBanner(it) { localError = null } }
                if (msg != null && mode == "login") {
                    ErrorBanner(msg!!.text) { vm.clearMessage() }
                }

                if (mode == "login") {
                    OutlinedTextField(
                        value = email,
                        onValueChange = { email = normalizeUsernameTyping(it) },
                        label = { Text("Username") },
                        placeholder = { Text("Email or 10-digit mobile", color = PharmaMuted) },
                        leadingIcon = { Icon(Icons.Default.Badge, null, tint = PharmaTeal) },
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(
                            keyboardType = KeyboardType.Email,
                            imeAction = ImeAction.Next,
                        ),
                        shape = RoundedCornerShape(10.dp),
                        colors = loginFieldColors(),
                        modifier = Modifier.fillMaxWidth(),
                    )
                    OutlinedTextField(
                        value = password,
                        onValueChange = { password = it },
                        label = { Text("Password") },
                        placeholder = { Text("Enter your password", color = PharmaMuted) },
                        leadingIcon = { Icon(Icons.Default.Lock, null, tint = PharmaTeal) },
                        trailingIcon = {
                            IconButton(onClick = { showPassword = !showPassword }) {
                                Icon(
                                    if (showPassword) Icons.Default.VisibilityOff else Icons.Default.Visibility,
                                    contentDescription = if (showPassword) "Hide password" else "Show password",
                                    tint = PharmaMuted,
                                )
                            }
                        },
                        visualTransformation = if (showPassword) VisualTransformation.None else PasswordVisualTransformation(),
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(
                            keyboardType = KeyboardType.Password,
                            imeAction = ImeAction.Done,
                        ),
                        keyboardActions = KeyboardActions(onDone = { submit() }),
                        shape = RoundedCornerShape(10.dp),
                        colors = loginFieldColors(),
                        modifier = Modifier.fillMaxWidth(),
                    )

                    Text(
                        "Forgot password?",
                        style = MaterialTheme.typography.labelLarge,
                        fontWeight = FontWeight.SemiBold,
                        color = PharmaTeal,
                        modifier = Modifier
                            .align(Alignment.End)
                            .clickable(enabled = !loading) { openForgot() }
                            .padding(vertical = 2.dp),
                    )

                    Button(
                        onClick = { submit() },
                        enabled = canSubmit,
                        shape = RoundedCornerShape(10.dp),
                        colors = ButtonDefaults.buttonColors(
                            containerColor = PharmaTeal,
                            contentColor = Color.White,
                        ),
                        modifier = Modifier.fillMaxWidth().height(46.dp),
                    ) {
                        if (loading) {
                            CircularProgressIndicator(
                                Modifier.size(18.dp),
                                color = Color.White,
                                strokeWidth = 2.dp,
                            )
                            Spacer(Modifier.width(8.dp))
                            Text("Signing in…", fontWeight = FontWeight.SemiBold)
                        } else {
                            Icon(Icons.AutoMirrored.Filled.Login, null, Modifier.size(18.dp))
                            Spacer(Modifier.width(8.dp))
                            Text("Sign In", fontWeight = FontWeight.SemiBold)
                        }
                    }

                    TextButton(
                        onClick = onRegister,
                        enabled = !loading,
                        modifier = Modifier.fillMaxWidth(),
                    ) {
                        Text(
                            "New pharmacy? Register (3-day free trial)",
                            color = PharmaTeal,
                            fontWeight = FontWeight.SemiBold,
                        )
                    }
                } else {
                    OutlinedTextField(
                        value = forgotUser,
                        onValueChange = {
                            forgotUser = normalizeUsernameTyping(it)
                            forgotTouched = true
                        },
                        label = { Text("Username") },
                        placeholder = { Text("Email or 10-digit mobile", color = PharmaMuted) },
                        leadingIcon = { Icon(Icons.Default.Badge, null, tint = PharmaTeal) },
                        singleLine = true,
                        isError = usernameHint(forgotUser, forgotTouched) != null,
                        supportingText = {
                            usernameHint(forgotUser, forgotTouched)?.let {
                                Text(it, color = MaterialTheme.colorScheme.error)
                            }
                        },
                        keyboardOptions = KeyboardOptions(
                            keyboardType = KeyboardType.Email,
                            imeAction = if (otpSent) ImeAction.Next else ImeAction.Done,
                        ),
                        keyboardActions = KeyboardActions(onDone = { if (!otpSent) sendOtp() }),
                        shape = RoundedCornerShape(10.dp),
                        colors = loginFieldColors(),
                        modifier = Modifier.fillMaxWidth(),
                    )

                    if (otpSent) {
                        OutlinedTextField(
                            value = forgotPassword,
                            onValueChange = { forgotPassword = it },
                            label = { Text("New password") },
                            placeholder = { Text("Enter new password", color = PharmaMuted) },
                            leadingIcon = { Icon(Icons.Default.Lock, null, tint = PharmaTeal) },
                            trailingIcon = {
                                IconButton(onClick = { showNewPassword = !showNewPassword }) {
                                    Icon(
                                        if (showNewPassword) Icons.Default.VisibilityOff else Icons.Default.Visibility,
                                        contentDescription = null,
                                        tint = PharmaMuted,
                                    )
                                }
                            },
                            visualTransformation = if (showNewPassword) VisualTransformation.None else PasswordVisualTransformation(),
                            singleLine = true,
                            keyboardOptions = KeyboardOptions(
                                keyboardType = KeyboardType.Password,
                                imeAction = ImeAction.Next,
                            ),
                            shape = RoundedCornerShape(10.dp),
                            colors = loginFieldColors(),
                            modifier = Modifier.fillMaxWidth(),
                        )
                        OutlinedTextField(
                            value = otp,
                            onValueChange = { otp = it.filter { c -> c.isDigit() }.take(16) },
                            label = { Text("OTP") },
                            placeholder = { Text("Enter OTP from email", color = PharmaMuted) },
                            leadingIcon = { Icon(Icons.Default.Key, null, tint = PharmaTeal) },
                            singleLine = true,
                            keyboardOptions = KeyboardOptions(
                                keyboardType = KeyboardType.Number,
                                imeAction = ImeAction.Done,
                            ),
                            keyboardActions = KeyboardActions(onDone = { resetPassword() }),
                            shape = RoundedCornerShape(10.dp),
                            colors = loginFieldColors(),
                            modifier = Modifier.fillMaxWidth(),
                        )
                    }

                    Button(
                        onClick = { if (otpSent) resetPassword() else sendOtp() },
                        enabled = !loading && forgotUser.isNotBlank() &&
                            (!otpSent || (forgotPassword.length >= 6 && otp.isNotBlank())),
                        shape = RoundedCornerShape(10.dp),
                        colors = ButtonDefaults.buttonColors(
                            containerColor = PharmaTeal,
                            contentColor = Color.White,
                        ),
                        modifier = Modifier.fillMaxWidth().height(46.dp),
                    ) {
                        if (loading) {
                            CircularProgressIndicator(
                                Modifier.size(18.dp),
                                color = Color.White,
                                strokeWidth = 2.dp,
                            )
                            Spacer(Modifier.width(8.dp))
                            Text(
                                if (otpSent) "Updating…" else "Sending OTP…",
                                fontWeight = FontWeight.SemiBold,
                            )
                        } else if (otpSent) {
                            Icon(Icons.Default.Key, null, Modifier.size(18.dp))
                            Spacer(Modifier.width(8.dp))
                            Text("Change Password", fontWeight = FontWeight.SemiBold)
                        } else {
                            Icon(Icons.AutoMirrored.Filled.Send, null, Modifier.size(18.dp))
                            Spacer(Modifier.width(8.dp))
                            Text("Send OTP", fontWeight = FontWeight.SemiBold)
                        }
                    }

                    OutlinedButton(
                        onClick = { if (!loading) backToLogin() },
                        enabled = !loading,
                        shape = RoundedCornerShape(10.dp),
                        modifier = Modifier.fillMaxWidth().height(44.dp),
                    ) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, null, Modifier.size(18.dp))
                        Spacer(Modifier.width(6.dp))
                        Text("Back to Login")
                    }
                }

                if (mode == "login") {
                    HorizontalDivider(color = Color(0x140F172A))

                    listOf(
                        "Faster billing, zero paperwork",
                        "Never lose money to expired stock",
                        "Every branch, every rupee — in one view",
                    ).forEach { line ->
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Icon(
                                Icons.Default.CheckCircle,
                                contentDescription = null,
                                tint = PharmaTeal,
                                modifier = Modifier.size(14.dp),
                            )
                            Spacer(Modifier.width(6.dp))
                            Text(line, style = MaterialTheme.typography.labelMedium, color = PharmaSlate)
                        }
                    }
                }

                Row(
                    Modifier
                        .fillMaxWidth()
                        .clip(RoundedCornerShape(10.dp))
                        .background(Color(0xFFF0FDFA))
                        .border(1.dp, Color(0xFFCCFBF1), RoundedCornerShape(10.dp))
                        .clickable { runCatching { uriHandler.openUri("tel:$SUPPORT_PHONE") } }
                        .padding(horizontal = 10.dp, vertical = 8.dp),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.Center,
                ) {
                    Icon(
                        Icons.Default.HeadsetMic,
                        contentDescription = null,
                        tint = PharmaTealDark,
                        modifier = Modifier.size(16.dp),
                    )
                    Spacer(Modifier.width(6.dp))
                    Text(
                        "Need help? Call support ",
                        style = MaterialTheme.typography.labelMedium,
                        color = PharmaMuted,
                    )
                    Text(
                        SUPPORT_PHONE,
                        style = MaterialTheme.typography.labelMedium,
                        fontWeight = FontWeight.Bold,
                        color = PharmaTealDark,
                    )
                }
            }

            Spacer(Modifier.height(14.dp))
            Text(
                "© Aurenyx Pharmatech Pvt. Ltd.",
                style = MaterialTheme.typography.labelSmall,
                color = Color.White.copy(alpha = 0.8f),
            )
        }
    }
}

@Composable
private fun InfoBanner(text: String, onDismiss: () -> Unit) {
    Surface(
        color = Color(0xFFD1FAE5),
        shape = RoundedCornerShape(8.dp),
        modifier = Modifier.fillMaxWidth(),
    ) {
        Row(
            Modifier.padding(10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(
                text,
                Modifier.weight(1f),
                style = MaterialTheme.typography.labelMedium,
                color = Color(0xFF166534),
            )
            TextButton(onClick = onDismiss) {
                Text("OK", style = MaterialTheme.typography.labelSmall, color = PharmaTealDark)
            }
        }
    }
}

@Composable
private fun loginFieldColors() = OutlinedTextFieldDefaults.colors(
    focusedBorderColor = PharmaTeal,
    unfocusedBorderColor = Color(0xFFCBD5E1),
    focusedLabelColor = PharmaTeal,
    cursorColor = PharmaTeal,
    focusedTextColor = PharmaSlate,
    unfocusedTextColor = PharmaSlate,
)
