package com.paperlesspharmacy.auraghartak.ui

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.CameraAlt
import androidx.compose.material.icons.outlined.Delete
import androidx.compose.material.icons.outlined.Person
import androidx.compose.material3.FilterChip
import androidx.compose.material3.FilterChipDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import coil.compose.AsyncImage
import com.paperlesspharmacy.auraghartak.BuildConfig

private val genderOptions = listOf("", "Male", "Female", "Other", "Prefer not to say")

@Composable
fun LoginScreen(
    auth: AuthViewModel,
    onRegister: () -> Unit,
    onSuccess: () -> Unit,
    onRiderLogin: () -> Unit = {},
) {
    Box(
        Modifier
            .fillMaxSize()
            .background(AuthBackgroundBrush)
            .statusBarsPadding(),
    ) {
        Column(
            Modifier
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .imePadding()
                .navigationBarsPadding()
                .padding(vertical = AuraDimens.spaceLg),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.Center,
        ) {
            BrandMark(large = true)
            Spacer(Modifier.height(AuraDimens.spaceLg))
            AuthFormColumn {
                Text(
                    "Welcome back",
                    style = MaterialTheme.typography.headlineMedium,
                    color = Ink,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth(),
                )
                Text(
                    "Sign in to order from nearby pharmacies",
                    style = MaterialTheme.typography.bodyMedium,
                    color = Mute,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth().padding(top = AuraDimens.spaceXs),
                )
                Spacer(Modifier.height(AuraDimens.spaceMd))
                SoftCard(elevated = true) {
                    Text("Log in", style = MaterialTheme.typography.titleSmall, color = TealDeep)
                    Spacer(Modifier.height(AuraDimens.spaceSm))
                    FormField("Mobile number", auth.loginPhone) {
                        auth.loginPhone = it.filter { ch -> ch.isDigit() }.take(10)
                    }
                    PasswordField("Password", auth.loginPassword) { auth.loginPassword = it }
                }
                if (auth.error != null) {
                    Spacer(Modifier.height(AuraDimens.spaceMd))
                    ErrorBanner(auth.error ?: "")
                }
                Spacer(Modifier.height(AuraDimens.spaceMd))
                PrimaryButton("Log in", loading = auth.loading) { auth.login(onSuccess) }
                TextButton(onClick = onRegister, modifier = Modifier.fillMaxWidth()) {
                    Text("New here? Create an account", color = TealDeep, fontWeight = FontWeight.SemiBold)
                }
                TextButton(onClick = onRiderLogin, modifier = Modifier.fillMaxWidth()) {
                    Text("Pharmacy delivery staff? Log in here", color = Mute)
                }
                Spacer(Modifier.height(AuraDimens.spaceSm))
                Text(
                    "App version ${BuildConfig.VERSION_NAME}",
                    style = MaterialTheme.typography.labelSmall,
                    color = Mute,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth(),
                )
            }
        }
    }
}

@Composable
fun RegisterScreen(auth: AuthViewModel, onLogin: () -> Unit, onSuccess: () -> Unit) {
    val picker = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri ->
        auth.onPhotoPicked(uri)
    }
    AuthScaffold(
        title = "Create account",
        subtitle = "Register once — use your profile at checkout",
        useGradientBackground = true,
    ) {
        SoftCard(elevated = true) {
            PhotoPicker(
                localUri = auth.photoUri,
                remoteUrl = "",
                photoRemoved = auth.photoRemoved,
                onPick = {
                    picker.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly))
                },
                onRemove = { auth.clearPhoto() },
            )
            FormField("Full name *", auth.fullName) { auth.fullName = it }
            FormField("Mobile number *", auth.phone) { auth.phone = it.filter { ch -> ch.isDigit() }.take(10) }
            FormField("Email", auth.email) { auth.email = it }
            PasswordField("Password *", auth.password) { auth.password = it }
            PasswordField("Confirm password *", auth.passwordConfirm) { auth.passwordConfirm = it }
            FormField("Delivery address *", auth.address, singleLine = false) { auth.address = it }
            FormField("Pincode", auth.pincode) { auth.pincode = it.filter { ch -> ch.isDigit() }.take(6) }
            FormField("City", auth.city) { auth.city = it }
            FormField("State", auth.state) { auth.state = it }
            FormField("Date of birth (dd-MM-yyyy)", auth.dateOfBirth) { auth.dateOfBirth = it }
            GenderField(auth.gender) { auth.gender = it }
        }
        if (auth.error != null) {
            Spacer(Modifier.height(AuraDimens.spaceMd))
            ErrorBanner(auth.error ?: "")
        }
        Spacer(Modifier.height(AuraDimens.spaceLg))
        PrimaryButton("Register", loading = auth.loading) { auth.register(onSuccess) }
        TextButton(onClick = onLogin, modifier = Modifier.fillMaxWidth()) {
            Text("Already registered? Log in", color = TealDeep, fontWeight = FontWeight.SemiBold)
        }
    }
}

@Composable
fun ProfileScreen(
    auth: AuthViewModel,
    vm: AuraViewModel,
    onOpenFavorite: () -> Unit,
    editing: Boolean,
    onEdit: () -> Unit,
    onCancelEdit: () -> Unit,
    onSaved: () -> Unit,
    onLogout: () -> Unit,
) {
    val user = auth.account
    Column(Modifier.fillMaxSize().background(Page)) {
        Column(
            Modifier
                .fillMaxWidth()
                .background(androidx.compose.ui.graphics.Brush.verticalGradient(listOf(TealDeep, Teal)))
                .statusBarsPadding()
                .padding(horizontal = AuraDimens.screenPad, vertical = AuraDimens.spaceLg),
        ) {
            Text("My profile", style = MaterialTheme.typography.headlineMedium, color = Color.White)
            Text("AuraGharTak customer account", style = MaterialTheme.typography.bodySmall, color = Mint)
        }
        Column(
            Modifier
                .weight(1f)
                .verticalScroll(rememberScrollState())
                .imePadding()
                .navigationBarsPadding()
                .padding(AuraDimens.spaceMd),
        ) {
            if (user == null) {
                EmptyHint("Not signed in", "Log in to view and edit your profile.")
                return@Column
            }
            if (!editing) {
                ProfileView(user)
                Spacer(Modifier.height(AuraDimens.spaceLg))
                EngagementProfileSection(vm, onOpenCatalog = onOpenFavorite)
                Spacer(Modifier.height(AuraDimens.spaceLg))
                PrimaryButton("Edit profile", onClick = onEdit)
                Spacer(Modifier.height(AuraDimens.spaceSm))
                SecondaryButton("Log out", onClick = onLogout)
            } else {
                val picker = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri ->
                    auth.onPhotoPicked(uri)
                }
                    SoftCard {
                    PhotoPicker(
                        localUri = auth.photoUri,
                        remoteUrl = user.photoUrl,
                        photoRemoved = auth.photoRemoved,
                        onPick = {
                            picker.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly))
                        },
                        onRemove = { auth.clearPhoto() },
                    )
                    FormField("Full name *", auth.fullName) { auth.fullName = it }
                    FormField("Mobile number *", auth.phone) { auth.phone = it.filter { ch -> ch.isDigit() }.take(10) }
                    FormField("Email", auth.email) { auth.email = it }
                    FormField("Delivery address", auth.address, singleLine = false) { auth.address = it }
                    FormField("Pincode", auth.pincode) { auth.pincode = it.filter { ch -> ch.isDigit() }.take(6) }
                    FormField("City", auth.city) { auth.city = it }
                    FormField("State", auth.state) { auth.state = it }
                    FormField("Date of birth (dd-MM-yyyy)", auth.dateOfBirth) { auth.dateOfBirth = it }
                    GenderField(auth.gender) { auth.gender = it }
                }
                Spacer(Modifier.height(AuraDimens.spaceMd))
                SoftCard {
                    Text("Confirm with your password", style = MaterialTheme.typography.titleSmall)
                    Spacer(Modifier.height(AuraDimens.spaceSm))
                    PasswordField("Current password *", auth.editPassword) { auth.editPassword = it }
                    PasswordField("New password (optional)", auth.newPassword) { auth.newPassword = it }
                }
                if (auth.error != null) {
                    Spacer(Modifier.height(AuraDimens.spaceMd))
                    ErrorBanner(auth.error ?: "")
                }
                Spacer(Modifier.height(AuraDimens.spaceLg))
                PrimaryButton("Save changes", loading = auth.loading) { auth.saveProfile(onSaved) }
                TextButton(onClick = onCancelEdit, modifier = Modifier.fillMaxWidth()) {
                    Text("Cancel", color = Mute)
                }
            }
        }
    }
}

@Composable
private fun ProfileView(user: com.paperlesspharmacy.auraghartak.data.AgtUserAccount) {
    SoftCard {
        Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.fillMaxWidth()) {
            ProfileAvatar(user.photoUrl, Modifier.size(96.dp))
            Spacer(Modifier.height(AuraDimens.spaceMd))
            Text(user.fullName, style = MaterialTheme.typography.titleLarge)
            Text(user.phone, style = MaterialTheme.typography.bodyMedium, color = Mute)
        }
        SectionDivider()
        ProfileInfoRow("Email", user.email)
        ProfileInfoRow("Address", user.address)
        ProfileInfoRow("Pincode", user.pincode)
        ProfileInfoRow("City", user.city)
        ProfileInfoRow("State", user.state)
        ProfileInfoRow("Date of birth", user.dateOfBirth)
        ProfileInfoRow("Gender", user.gender)
    }
}

@Composable
private fun AuthScaffold(
    title: String,
    subtitle: String,
    showBrand: Boolean = false,
    useGradientBackground: Boolean = false,
    content: @Composable () -> Unit,
) {
    val screenBg = if (useGradientBackground) {
        Modifier.background(AuthBackgroundBrush)
    } else {
        Modifier.background(Page)
    }
    Column(
        Modifier
            .fillMaxSize()
            .then(screenBg)
            .statusBarsPadding(),
    ) {
        if (useGradientBackground) {
            Column(
                Modifier.fillMaxWidth().padding(top = AuraDimens.spaceMd, bottom = AuraDimens.spaceSm),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                BrandMark()
                Spacer(Modifier.height(AuraDimens.spaceSm))
                Text(title, style = MaterialTheme.typography.titleLarge, color = Ink, textAlign = TextAlign.Center)
                Text(
                    subtitle,
                    style = MaterialTheme.typography.bodyMedium,
                    color = Mute,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.padding(horizontal = AuraDimens.spaceLg, vertical = AuraDimens.spaceXs),
                )
            }
        } else {
            AuraHeroHeader(
                eyebrow = if (showBrand) "Paperless Pharmacy" else null,
                title = title,
                subtitle = subtitle,
            )
        }
        Column(
            Modifier
                .weight(1f)
                .verticalScroll(rememberScrollState())
                .imePadding()
                .navigationBarsPadding()
                .padding(AuraDimens.screenPad),
        ) {
            if (useGradientBackground) {
                AuthFormColumn { content() }
            } else {
                content()
            }
        }
    }
}

@Composable
private fun PhotoPicker(
    localUri: Uri?,
    remoteUrl: String,
    photoRemoved: Boolean = false,
    onPick: () -> Unit,
    onRemove: () -> Unit = {},
) {
    val showImage = localUri != null || (!photoRemoved && remoteUrl.isNotBlank())
    Column(
        horizontalAlignment = Alignment.CenterHorizontally,
        modifier = Modifier.fillMaxWidth().padding(vertical = AuraDimens.spaceMd),
    ) {
        Box(
            Modifier
                .size(108.dp)
                .clip(CircleShape)
                .background(MintSoft)
                .border(2.dp, Teal.copy(alpha = 0.25f), CircleShape)
                .clickable(onClick = onPick),
            contentAlignment = Alignment.Center,
        ) {
            when {
                localUri != null -> AsyncImage(
                    model = localUri,
                    contentDescription = "Profile photo",
                    modifier = Modifier.fillMaxSize(),
                    contentScale = ContentScale.Crop,
                )
                !photoRemoved && remoteUrl.isNotBlank() -> AsyncImage(
                    model = remoteUrl,
                    contentDescription = "Profile photo",
                    modifier = Modifier.fillMaxSize(),
                    contentScale = ContentScale.Crop,
                )
                else -> Icon(Icons.Outlined.Person, contentDescription = null, tint = TealDeep, modifier = Modifier.size(48.dp))
            }
            Box(
                Modifier
                    .align(Alignment.BottomEnd)
                    .padding(4.dp)
                    .size(36.dp)
                    .clip(CircleShape)
                    .background(Teal),
                contentAlignment = Alignment.Center,
            ) {
                Icon(Icons.Outlined.CameraAlt, contentDescription = "Change photo", tint = Color.White, modifier = Modifier.size(20.dp))
            }
        }
        Text(
            "Profile photo",
            style = MaterialTheme.typography.labelMedium,
            color = Mute,
            modifier = Modifier.padding(top = AuraDimens.spaceSm),
        )
        if (showImage) {
            TextButton(onClick = onRemove) {
                Icon(Icons.Outlined.Delete, contentDescription = null, tint = Danger, modifier = Modifier.size(18.dp))
                Spacer(Modifier.size(6.dp))
                Text("Remove photo", color = Danger, fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

@Composable
private fun ProfileAvatar(url: String, modifier: Modifier = Modifier) {
    Box(
        modifier
            .clip(CircleShape)
            .background(MintSoft)
            .border(1.dp, Mint, CircleShape),
        contentAlignment = Alignment.Center,
    ) {
        if (url.isNotBlank()) {
            AsyncImage(model = url, contentDescription = "Profile photo", modifier = Modifier.fillMaxSize(), contentScale = ContentScale.Crop)
        } else {
            Icon(Icons.Outlined.Person, contentDescription = null, tint = TealDeep, modifier = Modifier.size(40.dp))
        }
    }
}

@Composable
internal fun PasswordField(label: String, value: String, onChange: (String) -> Unit) {
    Column(Modifier.fillMaxWidth().padding(vertical = AuraDimens.spaceSm)) {
        Text(label, style = MaterialTheme.typography.labelMedium, color = Mute)
        Spacer(Modifier.height(AuraDimens.spaceSm))
        OutlinedTextField(
            value = value,
            onValueChange = onChange,
            modifier = Modifier.fillMaxWidth(),
            singleLine = true,
            visualTransformation = PasswordVisualTransformation(),
            shape = MaterialTheme.shapes.medium,
            colors = auraTextFieldColors(),
            textStyle = MaterialTheme.typography.bodyLarge.copy(color = Ink),
        )
    }
}

@Composable
private fun GenderField(selected: String, onSelect: (String) -> Unit) {
    Column(Modifier.fillMaxWidth().padding(vertical = AuraDimens.spaceSm)) {
        Text("Gender", style = MaterialTheme.typography.labelMedium, color = Mute)
        Spacer(Modifier.height(AuraDimens.spaceSm))
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(AuraDimens.spaceSm)) {
            genderOptions.filter { it.isNotEmpty() }.forEach { option ->
                FilterChip(
                    selected = selected.equals(option, ignoreCase = true),
                    onClick = { onSelect(option) },
                    label = { Text(option, style = MaterialTheme.typography.labelMedium) },
                    colors = FilterChipDefaults.filterChipColors(
                        selectedContainerColor = Mint,
                        selectedLabelColor = TealDeep,
                    ),
                )
            }
        }
    }
}
