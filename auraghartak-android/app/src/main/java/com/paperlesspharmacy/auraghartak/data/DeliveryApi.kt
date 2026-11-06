package com.paperlesspharmacy.auraghartak.data

import android.content.Context
import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import com.paperlesspharmacy.auraghartak.BuildConfig
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.IOException
import java.net.URLEncoder
import java.util.concurrent.TimeUnit

data class GeoPoint(val latitude: Double, val longitude: Double)

data class LiveDelivery(
    val orderNo: String,
    val status: String,
    val trackingActive: Boolean,
    val riderName: String,
    val riderPhone: String,
    val rider: GeoPoint?,
    val riderAgeSeconds: Long,
    val riderSpeedMps: Double?,
    val trail: List<GeoPoint>,
    val store: GeoPoint?,
    val drop: GeoPoint?,
)

data class RiderSession(
    val accessToken: String,
    val refreshToken: String,
    val userId: Long,
    val name: String,
    val role: String,
)

data class RiderOrder(
    val orderId: Long,
    val orderNo: String,
    val status: String,
    val patientName: String,
    val patientContact: String,
    val patientAddress: String,
    val patientNote: String,
    val itemCount: Int,
    val amount: Double,
    val invoiceNo: String,
    val drop: GeoPoint?,
    val store: GeoPoint?,
    val trackingActive: Boolean,
)

class SessionExpiredException : ApiException("Your delivery login expired. Please log in again.")

/**
 * Live delivery: public customer map (/public/agt/live) and the staff rider endpoints (/agt/rider/…),
 * which use the normal Advanced staff login (JWT access + refresh).
 */
class DeliveryApi(context: Context) {
    private val prefs = RiderPrefs(context.applicationContext)
    private val customer = UserPrefs(context.applicationContext)
    private val base = BuildConfig.API_BASE.trimEnd('/')
    private val json = "application/json; charset=utf-8".toMediaType()
    private val client = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(20, TimeUnit.SECONDS)
        .build()

    fun live(orderNo: String): LiveDelivery {
        val customerToken = customer.authToken().ifBlank { null }
        val d = send(Request.Builder().url("$base/public/agt/live?order_no=${enc(orderNo)}").get(), customerToken).first.obj("data")
        val rider = d.obj("rider")
        return LiveDelivery(
            orderNo = d.str("order_no"),
            status = d.str("status"),
            trackingActive = d.bool("tracking_active"),
            riderName = d.str("rider_name"),
            riderPhone = d.str("rider_phone"),
            rider = rider.point(),
            riderAgeSeconds = rider.lng("age_seconds"),
            riderSpeedMps = rider.get("speed_mps")?.takeIf { it.isJsonPrimitive }?.asDouble,
            trail = d.arr("trail").mapNotNull { el ->
                if (!el.isJsonArray || el.asJsonArray.size() < 2) return@mapNotNull null
                GeoPoint(el.asJsonArray[0].asDouble, el.asJsonArray[1].asDouble)
            },
            store = d.obj("store").point(),
            drop = d.obj("drop").point(),
        )
    }

    fun login(login: String, password: String): RiderSession {
        val body = JsonObject().apply {
            addProperty("email", login.trim())
            addProperty("password", password)
        }
        val d = send(Request.Builder().url("$base/auth/login").post(body.toString().toRequestBody(json)), null).first.obj("data")
        val session = d.toSession()
        prefs.save(session)
        return session
    }

    fun logout() {
        val refresh = prefs.session()?.refreshToken.orEmpty()
        prefs.clear()
        if (refresh.isBlank()) return
        runCatching {
            val body = JsonObject().apply { addProperty("refresh_token", refresh) }
            send(Request.Builder().url("$base/auth/logout").post(body.toString().toRequestBody(json)), null)
        }
    }

    fun riderOrders(): List<RiderOrder> {
        val d = authed { token -> Request.Builder().url("$base/agt/rider/orders").get() to token }.obj("data")
        return d.arr("orders").mapNotNull { el ->
            val o = el.objOrNull() ?: return@mapNotNull null
            RiderOrder(
                orderId = o.lng("order_id"),
                orderNo = o.str("order_no"),
                status = o.str("status"),
                patientName = o.str("patient_name"),
                patientContact = o.str("patient_contact"),
                patientAddress = o.str("patient_address"),
                patientNote = o.str("patient_note"),
                itemCount = o.lng("item_count").toInt(),
                amount = o.dbl("amount"),
                invoiceNo = o.str("invoice_no"),
                drop = o.obj("drop").point(),
                store = o.obj("store").point(),
                trackingActive = o.bool("tracking_active"),
            )
        }
    }

    fun start(orderId: Long): String = action("start", orderId)

    fun delivered(orderId: Long): String = action("delivered", orderId)

    /** @return true while the server still has an active delivery for this rider. */
    fun ping(lat: Double, lng: Double, accuracy: Float?, speed: Float?, heading: Float?): Boolean {
        val body = JsonObject().apply {
            addProperty("latitude", lat)
            addProperty("longitude", lng)
            accuracy?.let { addProperty("accuracy", it) }
            speed?.let { addProperty("speed", it) }
            heading?.let { addProperty("heading", it) }
        }
        val d = authed { token ->
            Request.Builder().url("$base/agt/rider/location").post(body.toString().toRequestBody(json)) to token
        }.obj("data")
        return d.bool("keep_tracking")
    }

    private fun action(name: String, orderId: Long): String {
        val body = JsonObject().apply { addProperty("order_id", orderId) }
        val root = authed { token ->
            Request.Builder().url("$base/agt/rider/$name").post(body.toString().toRequestBody(json)) to token
        }
        return root.str("message")
    }

    /** Runs an authenticated call; on 401 refreshes the access token once and retries. */
    private fun authed(build: (String) -> Pair<Request.Builder, String>): JsonObject {
        val session = prefs.session() ?: throw SessionExpiredException()
        val (builder, token) = build(session.accessToken)
        val (root, code) = send(builder, token, allowUnauthorized = true)
        if (code != 401) return root
        val refreshed = refresh(session) ?: run {
            prefs.clear()
            throw SessionExpiredException()
        }
        val (retryBuilder, retryToken) = build(refreshed.accessToken)
        val (retryRoot, retryCode) = send(retryBuilder, retryToken, allowUnauthorized = true)
        if (retryCode == 401) {
            prefs.clear()
            throw SessionExpiredException()
        }
        return retryRoot
    }

    private fun refresh(old: RiderSession): RiderSession? = runCatching {
        val body = JsonObject().apply { addProperty("refresh_token", old.refreshToken) }
        val d = send(Request.Builder().url("$base/auth/refresh").post(body.toString().toRequestBody(json)), null).first.obj("data")
        d.toSession().also { prefs.save(it) }
    }.getOrNull()

    private fun send(builder: Request.Builder, token: String?, allowUnauthorized: Boolean = false): Pair<JsonObject, Int> {
        builder.header("Accept", "application/json").header("User-Agent", "AuraGharTak-Android/1.0")
        if (token != null) builder.header("Authorization", "Bearer $token")
        val (text, code) = try {
            client.newCall(builder.build()).execute().use { r -> r.body?.string().orEmpty() to r.code }
        } catch (_: IOException) {
            throw ApiException("No internet connection. Check your network and try again.")
        }
        if (code == 401 && allowUnauthorized) return JsonObject() to 401
        val root = runCatching { JsonParser.parseString(text).asJsonObject }.getOrNull()
            ?: throw ApiException("Unexpected response from the pharmacy server.")
        if (root.get("success")?.takeIf { it.isJsonPrimitive }?.asBoolean != true) {
            throw ApiException(root.firstError())
        }
        return root to code
    }

    private fun enc(v: String): String = URLEncoder.encode(v, Charsets.UTF_8.name())
}

class RiderPrefs(context: Context) {
    private val sp = context.getSharedPreferences("agt_rider", Context.MODE_PRIVATE)

    fun session(): RiderSession? {
        val access = sp.getString("access", null) ?: return null
        val refresh = sp.getString("refresh", null) ?: return null
        return RiderSession(
            accessToken = access,
            refreshToken = refresh,
            userId = sp.getLong("user_id", 0L),
            name = sp.getString("name", "").orEmpty(),
            role = sp.getString("role", "").orEmpty(),
        )
    }

    fun save(s: RiderSession) {
        sp.edit()
            .putString("access", s.accessToken)
            .putString("refresh", s.refreshToken)
            .putLong("user_id", s.userId)
            .putString("name", s.name)
            .putString("role", s.role)
            .apply()
    }

    fun clear() {
        sp.edit().clear().apply()
    }
}

private fun JsonObject.toSession(): RiderSession {
    val user = obj("user")
    val access = str("access_token")
    if (access.isBlank()) throw ApiException("Login failed. Please try again.")
    return RiderSession(
        accessToken = access,
        refreshToken = str("refresh_token"),
        userId = user.lng("id"),
        name = user.str("name"),
        role = user.str("role"),
    )
}

private fun JsonObject.firstError(): String {
    val errors = get("errors")?.takeIf { it.isJsonObject }?.asJsonObject
    errors?.entrySet()?.forEach { (_, v) ->
        if (v.isJsonArray && v.asJsonArray.size() > 0 && v.asJsonArray[0].isJsonPrimitive) {
            return v.asJsonArray[0].asString
        }
    }
    return str("message").ifBlank { "Something went wrong. Please try again." }
}

private fun JsonObject.point(): GeoPoint? {
    val la = get("latitude")?.takeIf { it.isJsonPrimitive }?.asDouble ?: return null
    val lo = get("longitude")?.takeIf { it.isJsonPrimitive }?.asDouble ?: return null
    return GeoPoint(la, lo)
}

private fun JsonObject.str(key: String): String =
    get(key)?.takeIf { it.isJsonPrimitive }?.asString?.trim().orEmpty()

private fun JsonObject.dbl(key: String): Double {
    val p = get(key)?.takeIf { it.isJsonPrimitive }?.asJsonPrimitive ?: return 0.0
    return if (p.isNumber) p.asDouble else p.asString.toDoubleOrNull() ?: 0.0
}

private fun JsonObject.lng(key: String): Long {
    val p = get(key)?.takeIf { it.isJsonPrimitive }?.asJsonPrimitive ?: return 0L
    return if (p.isNumber) p.asLong else p.asString.toLongOrNull() ?: 0L
}

private fun JsonObject.bool(key: String): Boolean {
    val p = get(key)?.takeIf { it.isJsonPrimitive }?.asJsonPrimitive ?: return false
    return when {
        p.isBoolean -> p.asBoolean
        p.isNumber -> p.asInt != 0
        else -> p.asString == "1" || p.asString.equals("true", true)
    }
}

private fun JsonObject.obj(key: String): JsonObject =
    get(key)?.takeIf { it.isJsonObject }?.asJsonObject ?: JsonObject()

private fun JsonObject.arr(key: String): List<JsonElement> =
    get(key)?.takeIf { it.isJsonArray }?.asJsonArray?.toList().orEmpty()

private fun JsonElement.objOrNull(): JsonObject? = if (isJsonObject) asJsonObject else null
