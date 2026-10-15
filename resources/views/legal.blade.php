{{-- Public Privacy Policy and Terms of Service (no sign-in): Google asks for both before the Drive connection can be published. --}}
@php
    $privacy = $page === 'privacy';
    $updated = '15 October 2026';
    $contact = 'official.iqbalstore@gmail.com';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $privacy ? 'Privacy Policy' : 'Terms of Service' }} - IQS CRM</title>
    <x-theme-head />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100 font-sans text-gray-900 antialiased">
<main class="mx-auto max-w-3xl px-4 py-10">
    <div class="rounded-2xl border border-gray-200 bg-white p-6 sm:p-10">
        <p class="text-sm font-semibold text-primary">IQS CRM · Iqbal Store</p>
        <h1 class="mt-1 text-2xl font-bold text-gray-900">{{ $privacy ? 'Privacy Policy' : 'Terms of Service' }}</h1>
        <p class="mt-1 text-sm text-gray-500">Last updated: {{ $updated }}</p>

        <div class="mt-6 space-y-5 text-[15px] leading-relaxed text-gray-700 [&_h2]:mt-6 [&_h2]:text-base [&_h2]:font-semibold [&_h2]:text-gray-900 [&_li]:ml-5 [&_li]:list-disc">
            @if ($privacy)
                <p>IQS CRM is the internal order and customer system of Iqbal Store, Bangladesh. It is used only by Iqbal Store staff. This policy explains what the system keeps, why, and how it is protected.</p>

                <h2>What we keep</h2>
                <ul>
                    <li>Customer details given when ordering: name, phone numbers, delivery address, orders, payments and delivery status.</li>
                    <li>Calls staff make to customers: the time, the outcome, notes, how satisfied the customer said they were, and the call recording or a screenshot of the call log.</li>
                    <li>Staff accounts: name, email, role and work activity inside the system.</li>
                </ul>

                <h2>Why we keep it</h2>
                <ul>
                    <li>To take, pack and deliver orders, and to handle returns and refunds.</li>
                    <li>To follow up with customers about their orders and their experience.</li>
                    <li>To check the quality of our service and the work of our staff.</li>
                </ul>

                <h2>Google Drive</h2>
                <p>When Iqbal Store connects its own Google account, IQS CRM saves call recordings and call-log screenshots into a folder in that account's Google Drive. The system asks only for permission to create and open the files it makes itself; it cannot see or change any other file in the Drive. The connection can be removed at any time from the Google account settings.</p>

                <h2>Who sees it</h2>
                <p>Only Iqbal Store staff signed in to IQS CRM, each limited by their role. We do not sell or rent personal data. Details are shared only with the delivery company that carries an order (name, phone and address), or when the law requires it.</p>

                <h2>How it is protected</h2>
                <p>The system runs over an encrypted connection (HTTPS), every user signs in with their own account, and recordings are open only to the person who made the call and to managers.</p>

                <h2>How long we keep it</h2>
                <p>Order and call history is kept while the customer relationship lasts and as long as needed for accounts and disputes. A customer who asks not to be called again is marked so, and is not called again.</p>

                <h2>Your choices</h2>
                <p>A customer may ask to see, correct or delete their details, or ask not to be called. Write to <a href="mailto:{{ $contact }}" class="text-primary underline">{{ $contact }}</a>.</p>
            @else
                <p>These terms cover the use of IQS CRM, the internal order and customer system of Iqbal Store, Bangladesh.</p>

                <h2>Who may use it</h2>
                <p>Only people Iqbal Store has given an account. Each person uses their own account and keeps the password to themselves.</p>

                <h2>Proper use</h2>
                <ul>
                    <li>Use customer details only for Iqbal Store work: orders, delivery, follow-up calls and service.</li>
                    <li>Do not copy, share or take customer details outside the system.</li>
                    <li>Record calls and keep notes honestly; do not enter false information.</li>
                    <li>Do not try to reach parts of the system your role does not allow.</li>
                </ul>

                <h2>Data and connected services</h2>
                <p>Data in the system belongs to Iqbal Store and is handled as described in the <a href="{{ route('legal.privacy') }}" class="text-primary underline">Privacy Policy</a>. When Iqbal Store connects Google Drive, call recordings are stored in Iqbal Store's own Google account.</p>

                <h2>Access may end</h2>
                <p>Iqbal Store may change or remove anyone's access at any time, for example when they leave or misuse the system.</p>

                <h2>Changes</h2>
                <p>These terms may be updated; the date above shows the latest version.</p>

                <h2>Contact</h2>
                <p><a href="mailto:{{ $contact }}" class="text-primary underline">{{ $contact }}</a></p>
            @endif
        </div>

        <p class="mt-8 border-t border-gray-100 pt-4 text-sm text-gray-500">
            <a href="{{ route('legal.privacy') }}" class="hover:text-primary">Privacy Policy</a> ·
            <a href="{{ route('legal.terms') }}" class="hover:text-primary">Terms of Service</a>
        </p>
    </div>
</main>
</body>
</html>
