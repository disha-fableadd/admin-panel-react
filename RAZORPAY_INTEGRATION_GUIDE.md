# Razorpay Payment & Email Integration Guide

A complete guide for testing with **Postman** and integrating with **React Frontend** for the Razorpay payment gateway and automated client/admin email notifications.

---

## 📌 Overview & Automated Email Behavior

When a payment is processed or paid through Razorpay:
1. **Transaction Status:** Updated to `paid`.
2. **Client Auto-Activation:** Client status automatically transitions to `Active`.
3. **Renewals & Expiry:** If the payment is a renewal (`payment_type: "renewal"`), the renewal record is marked `Completed` and the client's `expiry_date` is extended.
4. **Client Email:** A branded payment confirmation & receipt email is sent to the client's `work_email` (including login credentials for first-time purchases).
5. **Admin Email:** A real-time transaction alert is sent to all configured administrator recipients (`admin_email` / Super Admins).
6. **Duplicate Protection:** Built-in safeguards ensure duplicate emails are never sent even if both frontend verification and webhook fire for the same transaction.

---

## 🧪 Part 1: Postman Testing Guide

> **Base URL:** Replace `{{BASE_URL}}` with your local API URL, e.g.,  
> `http://127.0.0.1:8000/api` or `http://localhost/admin-panel-react/public/api`

---

### 1. Create Razorpay Order
Initializes a payment order on Razorpay and stores a pending transaction record.

- **Method:** `POST`
- **Endpoint:** `{{BASE_URL}}/razorpay/create-order`
- **Headers:**
  - `Content-Type`: `application/json`
  - `Accept`: `application/json`
- **Body (JSON):**
```json
{
  "amount": 500,
  "currency": "INR",
  "client_id": 1,
  "payment_type": "purchase",
  "description": "Standard Package Subscription",
  "name": "Acme Corp",
  "email": "client@example.com",
  "contact": "9876543210"
}
```
- **Response (`200 OK`):**
```json
{
  "success": true,
  "message": "Order created successfully.",
  "data": {
    "order_id": "order_XXXXXXXXXXXXXX",
    "amount": 50000,
    "amount_in_rupees": 500,
    "currency": "INR",
    "key_id": "rzp_test_XXXXXXXXXXXX",
    "name": "Your Company Name",
    "description": "Standard Package Subscription",
    "prefill": {
      "name": "Acme Corp",
      "email": "client@example.com",
      "contact": "9876543210"
    },
    "notes": {
      "client_id": 1,
      "payment_type": "purchase"
    },
    "transaction_id": 15
  }
}
```

---

### 2. Verify Payment (Triggers Paid Emails to Client & Admin)
Called after the customer completes the payment in the Razorpay popup.

- **Method:** `POST`
- **Endpoint:** `{{BASE_URL}}/razorpay/verify-payment`
- **Headers:**
  - `Content-Type`: `application/json`
  - `Accept`: `application/json`
- **Body (JSON):**
```json
{
  "razorpay_order_id": "order_XXXXXXXXXXXXXX",
  "razorpay_payment_id": "pay_YYYYYYYYYYYYYY",
  "razorpay_signature": "generated_hmac_sha256_signature",
  "client_id": 1,
  "payment_type": "purchase"
}
```
- **What happens upon this request:**
  - Verifies signature (or verifies live status via Razorpay API fallback).
  - Marks transaction `status = "paid"`.
  - Sets client `status = "Active"`.
  - Sends receipt email to `client@example.com`.
  - Sends payment notification to configured admin email.
- **Response (`200 OK`):**
```json
{
  "success": true,
  "message": "Payment verified successfully.",
  "data": {
    "transaction_id": 15,
    "order_id": "order_XXXXXXXXXXXXXX",
    "payment_link_id": null,
    "payment_id": "pay_YYYYYYYYYYYYYY",
    "status": "paid"
  }
}
```

---

### 3. Create Razorpay Payment Link
Generates a hosted payment link and immediately emails the invoice link to the client.

- **Method:** `POST`
- **Endpoint:** `{{BASE_URL}}/razorpay/payment-link`
- **Headers:**
  - `Content-Type`: `application/json`
  - `Accept`: `application/json`
- **Body (JSON):**
```json
{
  "amount": 1200,
  "client_id": 1,
  "currency": "INR",
  "payment_type": "renewal",
  "description": "Annual License Renewal"
}
```
- **What happens upon this request:**
  - Creates live Razorpay hosted payment link.
  - Generates `pending` transaction.
  - Automatically sends an invoice email with a **"Pay Now Online"** button to the client.
- **Response (`200 OK`):**
```json
{
  "success": true,
  "data": {
    "payment_link_id": "plink_ZZZZZZZZZZZZ",
    "short_url": "https://rzp.io/i/abcdef123",
    "amount": 1200
  }
}
```

---

### 4. Verify Payment Link (Once Paid by Client)
- **Method:** `POST`
- **Endpoint:** `{{BASE_URL}}/razorpay/verify-payment-link`
- **Headers:**
  - `Content-Type`: `application/json`
  - `Accept`: `application/json`
- **Body (JSON):**
```json
{
  "razorpay_payment_link_id": "plink_ZZZZZZZZZZZZ",
  "razorpay_payment_id": "pay_YYYYYYYYYYYYYY",
  "razorpay_signature": "signature_from_callback",
  "client_id": 1,
  "payment_type": "renewal"
}
```
- **What happens upon this request:**
  - Validates payment link transaction.
  - Extends client subscription & marks renewal `Completed`.
  - Sends paid confirmation emails to client & admin.

---

### 5. Check Order / Payment Link Status (With Auto-Sync)
Fetches transaction details and automatically syncs with Razorpay API if status was still pending.

- **Check Order:** `GET {{BASE_URL}}/razorpay/order/{orderId}`
- **Check Payment Link:** `GET {{BASE_URL}}/razorpay/payment-link/{linkId}`

---

### 6. Process Offline Renewal (`RenewalController`)
- **Method:** `POST`
- **Endpoint:** `{{BASE_URL}}/renewals/{id}/process`
- **Headers:**
  - `Authorization`: `Bearer YOUR_AUTH_TOKEN`
  - `Content-Type`: `application/json`
- **Body (JSON):**
```json
{
  "amount": 1500,
  "payment_method": "Cash",
  "notes": "Paid directly in office"
}
```
- **What happens:** Updates renewal, client expiry, logs paid transaction, and triggers payment confirmation emails.

---

## 💻 Part 2: React Developer Integration Guide

### Step 1: Load Razorpay Checkout Script
In your public `index.html` (or through a script loader in React):
```html
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
```

Alternatively, load it dynamically in a React helper:
```js
export const loadRazorpayScript = () => {
  return new Promise((resolve) => {
    if (window.Razorpay) {
      resolve(true);
      return;
    }
    const script = document.createElement('script');
    script.src = 'https://checkout.razorpay.com/v1/checkout.js';
    script.onload = () => resolve(true);
    script.onerror = () => resolve(false);
    document.body.appendChild(script);
  });
};
```

---

### Step 2: Complete Payment Flow Component / Function

```jsx
import React, { useState } from 'react';
import axios from 'axios';

// Configure your API client
const api = axios.create({
  baseURL: process.env.REACT_APP_API_BASE_URL || '/api',
});

export const RazorpayCheckoutButton = ({ 
  clientId, 
  amount, 
  paymentType = 'purchase', // 'purchase' | 'renewal'
  description = 'Payment for Package',
  onSuccess,
  onError 
}) => {
  const [loading, setLoading] = useState(false);

  const handlePayNow = async () => {
    setLoading(true);

    try {
      // 1. Ensure Razorpay script is loaded
      if (!window.Razorpay) {
        alert('Razorpay SDK failed to load. Are you online?');
        setLoading(false);
        return;
      }

      // 2. Call backend to create Razorpay order
      const orderResponse = await api.post('/razorpay/create-order', {
        amount: Number(amount),
        client_id: clientId,
        payment_type: paymentType,
        description: description,
      });

      if (!orderResponse.data.success) {
        throw new Error(orderResponse.data.message || 'Failed to create order');
      }

      const { order_id, amount: amountInPaise, key_id, name, prefill } = orderResponse.data.data;

      // 3. Setup Razorpay Checkout options
      const options = {
        key: key_id,
        amount: amountInPaise,
        currency: 'INR',
        name: name || 'Fablead Developers',
        description: description,
        order_id: order_id,
        prefill: {
          name: prefill?.name || '',
          email: prefill?.email || '',
          contact: prefill?.contact || '',
        },
        theme: {
          color: '#4f46e5', // Primary brand color
        },
        // 4. Razorpay success callback
        handler: async function (response) {
          try {
            // 5. Send payment confirmation to backend for verification
            const verifyResponse = await api.post('/razorpay/verify-payment', {
              razorpay_order_id: response.razorpay_order_id,
              razorpay_payment_id: response.razorpay_payment_id,
              razorpay_signature: response.razorpay_signature,
              client_id: clientId,
              payment_type: paymentType,
            });

            if (verifyResponse.data.success) {
              // NOTE: Payment receipt emails to both client and admin are triggered automatically by backend!
              if (onSuccess) {
                onSuccess(verifyResponse.data.data);
              } else {
                alert('Payment successful! Confirmation emails sent to client & admin.');
              }
            } else {
              throw new Error(verifyResponse.data.message || 'Payment verification failed');
            }
          } catch (verifyError) {
            console.error('Verification error:', verifyError);
            if (onError) onError(verifyError);
          }
        },
        modal: {
          ondismiss: function () {
            console.log('Payment checkout popup closed by user.');
            setLoading(false);
          },
        },
      };

      // 6. Open Razorpay Checkout modal
      const razorpayInstance = new window.Razorpay(options);
      razorpayInstance.open();

    } catch (err) {
      console.error('Error starting Razorpay checkout:', err);
      alert(err.response?.data?.message || err.message || 'Payment initiation failed.');
      if (onError) onError(err);
    } finally {
      setLoading(false);
    }
  };

  return (
    <button
      onClick={handlePayNow}
      disabled={loading}
      className="btn btn-primary"
      style={{
        backgroundColor: '#4f46e5',
        color: '#ffffff',
        padding: '10px 20px',
        borderRadius: '8px',
        border: 'none',
        fontWeight: '600',
        cursor: loading ? 'not-allowed' : 'pointer',
      }}
    >
      {loading ? 'Processing...' : `Pay ₹${amount}`}
    </button>
  );
};
```

---

### Step 3: Payment Link Generation (Admin / Staff Flow)

```jsx
export const generatePaymentLink = async (clientId, amount, paymentType = 'renewal') => {
  try {
    const response = await api.post('/razorpay/payment-link', {
      client_id: clientId,
      amount: Number(amount),
      payment_type: paymentType,
      description: `Invoice for Client #${clientId}`,
    });

    if (response.data.success) {
      const { short_url } = response.data.data;
      
      // Copy to clipboard
      navigator.clipboard.writeText(short_url);
      alert(`Payment link created & emailed to client!\nCopied URL: ${short_url}`);
      return short_url;
    }
  } catch (error) {
    console.error('Error creating payment link:', error);
    alert(error.response?.data?.message || 'Failed to create payment link');
  }
};
```

---

## ⚠️ Important Rules for the Frontend Developer

1. **Do NOT trigger emails manually from React:**
   - The backend automatically configures SMTP from database settings and delivers HTML receipts to both the client and administrator upon successful verification.
2. **Auto-Activation:**
   - Once `/api/razorpay/verify-payment` returns `success: true`, the client is already marked `Active` in the database. Simply re-fetch the client list or dashboard counts.
3. **Amounts are in Rupees:**
   - Send `amount` in Rupees (e.g., `500`). The backend handles conversion to paise (`50000`) for the Razorpay API.
4. **Signature Verification:**
   - Always forward all 3 parameters from the Razorpay handler: `razorpay_order_id`, `razorpay_payment_id`, and `razorpay_signature`.
