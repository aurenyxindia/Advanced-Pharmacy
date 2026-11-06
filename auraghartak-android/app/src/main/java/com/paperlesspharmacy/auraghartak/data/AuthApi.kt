package com.paperlesspharmacy.auraghartak.data

import com.google.gson.JsonObject
import com.google.gson.JsonParser
import com.paperlesspharmacy.auraghartak.BuildConfig
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.IOException
import java.util.concurrent.TimeUnit

class AuthApi {
    private val base = BuildConfig.API_BASE.trimEnd('/')
    private val json = "application/json; charset=utf-8".toMediaType()
    private val client = OkHttpClient.Builder()
        .connectTimeout(25, TimeUnit.SECONDS)
        .readTimeout(45, TimeUnit.SECONDS)
        .build()

    fun register(payload: JsonObject): Pair<String, AgtUserAccount> {
        val root = post("$base/public/agt-users/register", payload, token = null)
        val data = root.obj("data")
        return data.str("token") to parseUser(data.obj("user"))
    }

    fun login(phone: String, password: String): Pair<String, AgtUserAccount> {
        val body = JsonObject().apply {
            addProperty("phone", phone)
            addProperty("password", password)
        }
        val root = post("$base/public/agt-users/login", body, token = null)
        val data = root.obj("data")
        return data.str("token") to parseUser(data.obj("user"))
    }

    fun logout(token: String) {
        runCatching { post("$base/public/agt-users/logout", JsonObject(), token) }
    }

    fun me(token: String): AgtUserAccount {
        val root = get("$base/public/agt-users/me", token)
        return parseUser(root.obj("data").obj("user"))
    }

    fun updateProfile(token: String, payload: JsonObject): AgtUserAccount {
        val root = post("$base/public/agt-users/profile", payload, token)
        return parseUser(root.obj("data").obj("user"))
    }

    private fun get(url: String, token: String?): JsonObject = call(
        Request.Builder().url(url).get().apply { token?.let { header("Authorization", "Bearer $it") } }.build()
    )

    private fun post(url: String, body: JsonObject, token: String?): JsonObject = call(
        Request.Builder()
            .url(url)
            .post(body.toString().toRequestBody(json))
            .apply { token?.let { header("Authorization", "Bearer $it") } }
            .build()
    )

    private fun call(request: Request): JsonObject {
        val req = request.newBuilder()
            .header("Accept", "application/json")
            .header("User-Agent", "AuraGharTak-Android/1.0")
            .build()
        return try {
            client.newCall(req).execute().use { response ->
                val text = response.body?.string().orEmpty()
                val root = try {
                    JsonParser.parseString(text).asJsonObject
                } catch (_: Exception) {
                    throw ApiException("Unexpected response from the server.")
                }
                val ok = root.get("success")?.takeIf { !it.isJsonNull }?.asBoolean == true
                if (!response.isSuccessful || !ok) {
                    val msg = root.str("message").ifBlank { validationMessage(root) }
                    throw ApiException(msg.ifBlank { "Request failed." })
                }
                root
            }
        } catch (e: ApiException) {
            throw e
        } catch (_: IOException) {
            throw ApiException("No internet connection. Check your network and try again.")
        }
    }

    private fun validationMessage(root: JsonObject): String {
        val errors = when {
            root.has("errors") && root.get("errors").isJsonObject -> root.getAsJsonObject("errors")
            else -> JsonObject()
        }
        for (key in errors.keySet()) {
            val el = errors.get(key) ?: continue
            if (el.isJsonArray && el.asJsonArray.size() > 0) {
                return el.asJsonArray[0].asString
            }
        }
        return ""
    }

    private fun parseUser(o: JsonObject): AgtUserAccount = AgtUserAccount(
        id = o.lng("id"),
        fullName = o.str("full_name"),
        email = o.str("email"),
        phone = o.str("phone"),
        address = o.str("address"),
        pincode = o.str("pincode"),
        city = o.str("city"),
        state = o.str("state"),
        dateOfBirth = o.str("date_of_birth"),
        gender = o.str("gender"),
        photoUrl = o.str("photo_url"),
    )
}

private fun JsonObject.str(key: String): String {
    val el = get(key) ?: return ""
    if (el.isJsonNull || !el.isJsonPrimitive) return ""
    return el.asString.trim()
}

private fun JsonObject.lng(key: String): Long {
    val el = get(key) ?: return 0L
    if (el.isJsonNull || !el.isJsonPrimitive) return 0L
    val p = el.asJsonPrimitive
    return if (p.isNumber) p.asLong else p.asString.toLongOrNull() ?: 0L
}

private fun JsonObject.obj(key: String): JsonObject {
    val el = get(key)
    return if (el != null && el.isJsonObject) el.asJsonObject else JsonObject()
}
