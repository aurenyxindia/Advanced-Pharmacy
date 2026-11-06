package com.paperlesspharmacy.auraghartak.ui

import android.app.Application
import android.graphics.BitmapFactory
import android.net.Uri
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.google.gson.JsonObject
import com.paperlesspharmacy.auraghartak.data.ApiException
import com.paperlesspharmacy.auraghartak.data.AgtUserAccount
import com.paperlesspharmacy.auraghartak.data.AuthApi
import com.paperlesspharmacy.auraghartak.data.UserPrefs
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.ByteArrayOutputStream
import android.util.Base64

class AuthViewModel(app: Application) : AndroidViewModel(app) {
    private val api = AuthApi()
    private val prefs = UserPrefs(app)

    var account by mutableStateOf(prefs.account())
        private set
    var token by mutableStateOf(prefs.authToken())
        private set

    val isLoggedIn: Boolean get() = token.isNotBlank() && account != null

    var loading by mutableStateOf(false)
        private set
    var error by mutableStateOf<String?>(null)
        private set

    // Register / edit form
    var fullName by mutableStateOf("")
    var email by mutableStateOf("")
    var phone by mutableStateOf("")
    var password by mutableStateOf("")
    var passwordConfirm by mutableStateOf("")
    var address by mutableStateOf("")
    var pincode by mutableStateOf("")
    var city by mutableStateOf("")
    var state by mutableStateOf("")
    var dateOfBirth by mutableStateOf("")
    var gender by mutableStateOf("")
    var photoUri by mutableStateOf<Uri?>(null)
    var photoBase64 by mutableStateOf<String?>(null)
    /** Cleared locally; sent as remove_photo=true on profile save. */
    var photoRemoved by mutableStateOf(false)

    // Login
    var loginPhone by mutableStateOf("")
    var loginPassword by mutableStateOf("")

    // Profile edit auth
    var editPassword by mutableStateOf("")
    var newPassword by mutableStateOf("")

    init {
        account?.let { fillForm(it) }
    }

    fun clearError() {
        error = null
    }

    fun fillForm(user: AgtUserAccount) {
        fullName = user.fullName
        email = user.email
        phone = user.phone
        address = user.address
        pincode = user.pincode
        city = user.city
        state = user.state
        dateOfBirth = user.dateOfBirth
        gender = user.gender
        photoUri = null
        photoBase64 = null
        photoRemoved = false
    }

    fun onPhotoPicked(uri: Uri?) {
        photoUri = uri
        photoRemoved = false
        if (uri == null) {
            photoBase64 = null
            return
        }
        viewModelScope.launch {
            photoBase64 = withContext(Dispatchers.IO) { uriToBase64(uri) }
        }
    }

    fun clearPhoto() {
        photoUri = null
        photoBase64 = null
        photoRemoved = true
    }

    fun login(onSuccess: () -> Unit) {
        error = null
        viewModelScope.launch {
            loading = true
            try {
                val (tok, user) = withContext(Dispatchers.IO) {
                    api.login(loginPhone.trim(), loginPassword)
                }
                persist(tok, user)
                onSuccess()
            } catch (e: ApiException) {
                error = e.message
            } finally {
                loading = false
            }
        }
    }

    fun register(onSuccess: () -> Unit) {
        when {
            fullName.isBlank() -> error = "Enter your full name."
            phone.filter { it.isDigit() }.length < 10 -> error = "Enter a 10-digit mobile number."
            password.length < 4 -> error = "Password must be at least 4 characters."
            password != passwordConfirm -> error = "Passwords do not match."
            address.isBlank() -> error = "Enter your delivery address."
            else -> error = null
        }
        if (error != null) return

        val body = buildPayload(includePassword = true)
        viewModelScope.launch {
            loading = true
            try {
                val (tok, user) = withContext(Dispatchers.IO) { api.register(body) }
                persist(tok, user)
                onSuccess()
            } catch (e: ApiException) {
                error = e.message
            } finally {
                loading = false
            }
        }
    }

    fun refreshProfile() {
        val t = token
        if (t.isBlank()) return
        viewModelScope.launch {
            try {
                val user = withContext(Dispatchers.IO) { api.me(t) }
                account = user
                prefs.saveSession(t, user)
            } catch (_: ApiException) {
            }
        }
    }

    fun saveProfile(onSuccess: () -> Unit) {
        when {
            fullName.isBlank() -> error = "Enter your full name."
            phone.filter { it.isDigit() }.length < 10 -> error = "Enter a 10-digit mobile number."
            editPassword.isBlank() -> error = "Enter your password to save changes."
            newPassword.isNotBlank() && newPassword.length < 4 -> error = "New password must be at least 4 characters."
            else -> error = null
        }
        if (error != null) return

        val t = token
        if (t.isBlank()) {
            error = "Please log in again."
            return
        }

        val body = buildPayload(includePassword = false).apply {
            addProperty("password", editPassword)
            if (newPassword.isNotBlank()) addProperty("new_password", newPassword)
        }

        viewModelScope.launch {
            loading = true
            try {
                val user = withContext(Dispatchers.IO) { api.updateProfile(t, body) }
                persist(t, user)
                editPassword = ""
                newPassword = ""
                onSuccess()
            } catch (e: ApiException) {
                error = e.message
            } finally {
                loading = false
            }
        }
    }

    fun logout(onDone: () -> Unit) {
        val t = token
        viewModelScope.launch {
            loading = true
            withContext(Dispatchers.IO) { if (t.isNotBlank()) api.logout(t) }
            prefs.clearSession()
            token = ""
            account = null
            loginPhone = ""
            loginPassword = ""
            editPassword = ""
            newPassword = ""
            loading = false
            onDone()
        }
    }

    private fun persist(tok: String, user: AgtUserAccount) {
        token = tok
        account = user
        prefs.saveSession(tok, user)
        fillForm(user)
        photoUri = null
        photoBase64 = null
        photoRemoved = false
    }

    private fun buildPayload(includePassword: Boolean): JsonObject {
        val body = JsonObject().apply {
            addProperty("full_name", fullName.trim())
            addProperty("email", email.trim())
            addProperty("phone", phone.trim())
            addProperty("address", address.trim())
            addProperty("pincode", pincode.trim())
            addProperty("city", city.trim())
            addProperty("state", state.trim())
            addProperty("date_of_birth", dateOfBirth.trim())
            addProperty("gender", gender.trim())
            if (includePassword) {
                addProperty("password", password)
                addProperty("password_confirm", passwordConfirm)
            }
        }
        if (photoRemoved) {
            body.addProperty("remove_photo", true)
        } else {
            photoBase64?.let { body.addProperty("photo_base64", it) }
        }
        return body
    }

    private fun uriToBase64(uri: Uri): String? {
        val ctx = getApplication<Application>()
        return try {
            ctx.contentResolver.openInputStream(uri)?.use { input ->
                val bitmap = BitmapFactory.decodeStream(input) ?: return null
                val scaled = if (bitmap.width > 1200 || bitmap.height > 1200) {
                    val ratio = minOf(1200f / bitmap.width, 1200f / bitmap.height)
                    android.graphics.Bitmap.createScaledBitmap(
                        bitmap,
                        (bitmap.width * ratio).toInt(),
                        (bitmap.height * ratio).toInt(),
                        true,
                    )
                } else bitmap
                val out = ByteArrayOutputStream()
                scaled.compress(android.graphics.Bitmap.CompressFormat.JPEG, 85, out)
                "data:image/jpeg;base64," + Base64.encodeToString(out.toByteArray(), Base64.NO_WRAP)
            }
        } catch (_: Exception) {
            null
        }
    }
}
