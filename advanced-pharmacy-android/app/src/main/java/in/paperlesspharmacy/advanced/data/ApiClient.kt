package `in`.paperlesspharmacy.advanced.data

import com.google.gson.Gson
import com.google.gson.JsonArray
import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import `in`.paperlesspharmacy.advanced.BuildConfig
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.MultipartBody
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.IOException
import java.util.concurrent.TimeUnit

class ApiException(
    message: String,
    val status: Int = 0,
    val body: JsonObject? = null,
) : IOException(message)

class ApiClient(
    private val tokens: SessionStore,
    private val gson: Gson = Gson(),
) {
    private val base = BuildConfig.API_BASE.trimEnd('/')
    private val jsonType = "application/json; charset=utf-8".toMediaType()

    private val client = OkHttpClient.Builder()
        .connectTimeout(25, TimeUnit.SECONDS)
        .readTimeout(90, TimeUnit.SECONDS)
        .writeTimeout(90, TimeUnit.SECONDS)
        .addInterceptor { chain ->
            val req = chain.request()
            val token = tokens.accessToken
            val builder = req.newBuilder()
                .header("Accept", "application/json")
                .header("User-Agent", "AdvancedPharmacy-Android/1.0")
            if (token != null && req.header("Authorization") == null) {
                builder.header("Authorization", "Bearer $token")
            }
            chain.proceed(builder.build())
        }
        .authenticator { _, response ->
            if (response.code != 401) return@authenticator null
            val refresh = tokens.refreshToken ?: return@authenticator null
            val refreshed = runCatching { refreshTokens(refresh) }.getOrNull() ?: return@authenticator null
            tokens.storeTokens(refreshed.accessToken, refreshed.refreshToken, tokens.user)
            response.request.newBuilder()
                .header("Authorization", "Bearer ${refreshed.accessToken}")
                .build()
        }
        .build()

    fun get(path: String, query: Map<String, String> = emptyMap(), auth: Boolean = true): JsonObject {
        val url = buildUrl(path, query)
        val req = Request.Builder().url(url).get()
        if (!auth) req.header("Authorization", "")
        return execute(req.build())
    }

    fun post(path: String, body: JsonElement = JsonObject(), auth: Boolean = true): JsonObject {
        val req = Request.Builder()
            .url("$base/${path.trimStart('/')}")
            .post(body.toString().toRequestBody(jsonType))
        if (!auth) req.header("Authorization", "")
        return execute(req.build())
    }

    /** JSON POST for slow server work (AI bill extraction) — web waits up to ~280 s. */
    fun postLong(path: String, body: JsonElement, timeoutSeconds: Long = 280): JsonObject {
        val req = Request.Builder()
            .url("$base/${path.trimStart('/')}")
            .post(body.toString().toRequestBody(jsonType))
            .build()
        val slow = client.newBuilder()
            .readTimeout(timeoutSeconds, TimeUnit.SECONDS)
            .writeTimeout(timeoutSeconds, TimeUnit.SECONDS)
            .callTimeout(timeoutSeconds + 20, TimeUnit.SECONDS)
            .build()
        return execute(req, slow)
    }

    fun put(path: String, body: JsonElement): JsonObject =
        execute(
            Request.Builder()
                .url("$base/${path.trimStart('/')}")
                .put(body.toString().toRequestBody(jsonType))
                .build(),
        )

    fun patch(path: String, body: JsonElement): JsonObject =
        execute(
            Request.Builder()
                .url("$base/${path.trimStart('/')}")
                .patch(body.toString().toRequestBody(jsonType))
                .build(),
        )

    fun delete(path: String): JsonObject =
        execute(
            Request.Builder()
                .url("$base/${path.trimStart('/')}")
                .delete()
                .build(),
        )

    /** Multipart upload (import CSV, etc.). */
    fun postMultipart(
        path: String,
        formFields: Map<String, String> = emptyMap(),
        fileField: String,
        fileName: String,
        fileBytes: ByteArray,
        mimeType: String = "text/csv",
    ): JsonObject {
        return postMultipartMany(
            path = path,
            formFields = formFields,
            files = listOf(MultipartFile(fileField, fileName, fileBytes, mimeType)),
        )
    }

    /**
     * Multipart with zero or more files (Support tickets use `screenshots[]`,
     * `reply_screenshots[]`, `admin_files[]`, etc.).
     */
    fun postMultipartMany(
        path: String,
        formFields: Map<String, String> = emptyMap(),
        files: List<MultipartFile> = emptyList(),
    ): JsonObject {
        val multipart = MultipartBody.Builder()
            .setType(MultipartBody.FORM)
            .apply {
                formFields.forEach { (k, v) -> addFormDataPart(k, v) }
                files.forEach { f ->
                    addFormDataPart(
                        f.field,
                        f.fileName,
                        f.bytes.toRequestBody(f.mimeType.toMediaType()),
                    )
                }
            }
            .build()
        val req = Request.Builder()
            .url("$base/${path.trimStart('/')}")
            .post(multipart)
            .build()
        return execute(req)
    }

    /** Binary download (PO PDF, etc.) — same auth as JSON calls. */
    fun getBytes(path: String, query: Map<String, String> = emptyMap()): ByteArray {
        val url = buildUrl(path, query)
        return try {
            client.newCall(Request.Builder().url(url).get().build()).execute().use { response ->
                if (!response.isSuccessful) {
                    val text = response.body?.string().orEmpty()
                    val msg = runCatching {
                        JsonParser.parseString(text).asJsonObject.str("message")
                    }.getOrDefault("")
                    throw ApiException(msg.ifBlank { "Download failed (${response.code})." })
                }
                response.body?.bytes() ?: throw ApiException("Empty response.")
            }
        } catch (e: ApiException) {
            throw e
        } catch (e: IOException) {
            throw ApiException(networkMessage(e))
        }
    }

    fun subscriptionPlans(): List<JsonObject> {
        val root = get("public/subscription-plans", auth = false)
        val arr = root.obj("data").arr("plans")
        return (0 until arr.size()).map { arr.get(it).asJsonObject }
    }

    fun registerPharmacy(body: JsonObject): JsonObject =
        post("auth/register", body, auth = false)

    fun login(email: String, password: String): AuthTokens {
        val body = JsonObject().apply {
            addProperty("email", email)
            addProperty("password", password)
        }
        val root = post("auth/login", body, auth = false)
        return parseAuth(root.obj("data"))
    }

    fun subscriptionStatus(): JsonObject =
        get("subscription/status").obj("data").obj("subscription")

    /** Create Razorpay order — amount locked server-side. */
    fun createSubscriptionCheckout(planCode: String): JsonObject {
        val body = JsonObject().apply { addProperty("plan_code", planCode) }
        return post("subscription/checkout", body).obj("data")
    }

    /** Verify Checkout success signature and activate plan. */
    fun verifySubscriptionPayment(orderId: String, paymentId: String, signature: String): JsonObject {
        val body = JsonObject().apply {
            addProperty("razorpay_order_id", orderId)
            addProperty("razorpay_payment_id", paymentId)
            addProperty("razorpay_signature", signature)
        }
        return post("subscription/verify", body).obj("data")
    }

    /** Retail/web parity — OTP emailed to registered address. */
    fun sendForgotPasswordOtp(username: String): JsonObject {
        val body = JsonObject().apply { addProperty("username", username) }
        return post("forgot-password/send-otp", body, auth = false)
    }

    fun resetForgotPassword(username: String, securityCode: String, password: String): JsonObject {
        val body = JsonObject().apply {
            addProperty("username", username)
            addProperty("security_code", securityCode)
            addProperty("password", password)
        }
        return post("forgot-password/reset", body, auth = false)
    }

    fun logout() {
        val refresh = tokens.refreshToken ?: return
        runCatching {
            post("auth/logout", JsonObject().apply { addProperty("refresh_token", refresh) })
        }
    }

    private fun refreshTokens(refreshToken: String): AuthTokens {
        val root = post(
            "auth/refresh",
            JsonObject().apply { addProperty("refresh_token", refreshToken) },
            auth = false,
        )
        return parseAuth(root.obj("data"))
    }

    private fun parseAuth(data: JsonObject): AuthTokens {
        val user = data.obj("user")
        val sub = data.obj("subscription")
        return AuthTokens(
            accessToken = data.str("access_token"),
            refreshToken = data.str("refresh_token"),
            user = AuthUser(
                id = user.jInt("id"),
                name = user.str("name"),
                email = user.str("email"),
                role = user.str("role"),
                clientId = user.optInt("client_id"),
                branchId = user.optInt("branch_id"),
                scope = user.optStr("scope"),
            ),
            needsPayment = sub.bool("needs_payment"),
            trialDaysLeft = sub.optInt("days_left"),
            trialActive = sub.bool("trial_active"),
            subscriptionStatus = sub.str("status"),
        )
    }

    private fun buildUrl(path: String, query: Map<String, String>): String {
        val httpUrl = "$base/${path.trimStart('/')}".toHttpUrlOrNull()
            ?: throw ApiException("Invalid API path.")
        val builder = httpUrl.newBuilder()
        query.forEach { (k, v) -> if (v.isNotBlank()) builder.addQueryParameter(k, v) }
        return builder.build().toString()
    }

    private fun execute(request: Request, http: OkHttpClient = client): JsonObject {
        return try {
            http.newCall(request).execute().use { response ->
                val text = response.body?.string().orEmpty()
                val root = try {
                    JsonParser.parseString(text).asJsonObject
                } catch (_: Exception) {
                    throw ApiException("Unexpected response from server.")
                }
                val ok = root.get("success")?.takeIf { !it.isJsonNull }?.asBoolean == true
                if (!response.isSuccessful || !ok) {
                    val msg = root.str("message").ifBlank { validationMessage(root) }
                    throw ApiException(msg.ifBlank { "Request failed (${response.code})." }, response.code, root)
                }
                root
            }
        } catch (e: ApiException) {
            throw e
        } catch (e: IOException) {
            throw ApiException(networkMessage(e))
        }
    }

    private fun networkMessage(e: IOException): String = when (e) {
        is java.net.UnknownHostException, is java.net.ConnectException ->
            "No internet connection. Check Wi-Fi / mobile data and try again."
        is java.net.SocketTimeoutException -> "Server is taking too long to respond. Please try again."
        else -> e.message ?: "Network error."
    }

    private fun validationMessage(root: JsonObject): String {
        val errors = root.get("errors")?.takeIf { it.isJsonObject }?.asJsonObject ?: return ""
        return errors.entrySet().mapNotNull { (k, v) ->
            val msg = runCatching {
                when {
                    v.isJsonArray -> v.asJsonArray.joinToString { it.asString }
                    else -> v.asString
                }
            }.getOrNull() ?: return@mapNotNull null
            "$k: $msg"
        }.joinToString("\n")
    }
}

data class MultipartFile(
    val field: String,
    val fileName: String,
    val bytes: ByteArray,
    val mimeType: String = "application/octet-stream",
)

data class AuthUser(
    val id: Int,
    val name: String,
    val email: String,
    val role: String,
    val clientId: Int?,
    val branchId: Int?,
    val scope: String?,
)

data class AuthTokens(
    val accessToken: String,
    val refreshToken: String,
    val user: AuthUser,
    val needsPayment: Boolean = false,
    val trialDaysLeft: Int? = null,
    val trialActive: Boolean = false,
    val subscriptionStatus: String = "",
)

data class ModuleRight(
    val moduleCode: String,
    val moduleName: String,
    val canView: Boolean,
    val canEdit: Boolean,
    val canDelete: Boolean,
)

data class PaginatedRows(
    val rows: List<JsonObject>,
    val total: Int,
    val page: Int,
    val perPage: Int,
)

fun JsonObject.obj(key: String): JsonObject =
    get(key)?.takeIf { it.isJsonObject }?.asJsonObject ?: JsonObject()

fun JsonObject.arr(key: String): JsonArray =
    get(key)?.takeIf { it.isJsonArray }?.asJsonArray ?: JsonArray()

fun JsonObject.str(key: String): String =
    get(key)?.takeIf { !it.isJsonNull }?.asString ?: ""

/** Named `jInt` because `int` is not usable as an import/call in Kotlin. */
fun JsonObject.jInt(key: String): Int =
    get(key)?.takeIf { !it.isJsonNull }?.asInt
        ?: get(key)?.takeIf { !it.isJsonNull && it.isJsonPrimitive && it.asJsonPrimitive.isNumber }?.asDouble?.toInt()
        ?: 0

fun JsonObject.amount(key: String): Double {
    val el = get(key) ?: return 0.0
    if (el.isJsonNull) return 0.0
    if (!el.isJsonPrimitive) return 0.0
    val prim = el.asJsonPrimitive
    if (prim.isNumber) return prim.asDouble
    return prim.asString.trim().toDoubleOrNull() ?: 0.0
}

fun JsonObject.optInt(key: String): Int? =
    get(key)?.takeIf { !it.isJsonNull }?.asInt

fun JsonObject.optStr(key: String): String? =
    get(key)?.takeIf { !it.isJsonNull }?.asString

fun JsonObject.bool(key: String): Boolean =
    when {
        get(key)?.isJsonPrimitive == true && get(key).asJsonPrimitive.isBoolean -> get(key).asBoolean
        get(key)?.isJsonPrimitive == true -> get(key).asInt != 0
        else -> false
    }

fun JsonObject.toPaginated(): PaginatedRows {
    val data = arr("data")
    val rows = (0 until data.size()).map { data.get(it).asJsonObject }
    return PaginatedRows(
        rows = rows,
        total = jInt("total"),
        page = jInt("page").takeIf { it > 0 } ?: 1,
        perPage = jInt("per_page").takeIf { it > 0 } ?: rows.size,
    )
}

fun JsonObject.dataRows(): List<JsonObject> {
    val el = get("data") ?: return emptyList()
    return when {
        el.isJsonArray -> (0 until el.asJsonArray.size()).map { el.asJsonArray.get(it).asJsonObject }
        el.isJsonObject -> {
            val inner = el.asJsonObject
            if (inner.has("data") && inner.get("data").isJsonArray) {
                val arr = inner.getAsJsonArray("data")
                (0 until arr.size()).map { arr.get(it).asJsonObject }
            } else listOf(inner)
        }
        else -> emptyList()
    }
}

fun paginatedFromApiData(data: JsonObject): PaginatedRows {
    val rowsArr = data.arr("data")
    val rows = (0 until rowsArr.size()).map { rowsArr.get(it).asJsonObject }
    return PaginatedRows(
        rows = rows,
        total = data.jInt("total"),
        page = data.jInt("page").takeIf { it > 0 } ?: 1,
        perPage = data.jInt("per_page").takeIf { it > 0 } ?: 20,
    )
}
