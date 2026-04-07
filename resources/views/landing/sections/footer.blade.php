<footer class="bg-primary text-white/80 py-8 px-6">
    <div class="max-w-6xl mx-auto flex flex-col md:flex-row justify-between items-center gap-4">
        <p class="text-sm">
            &copy; {{ date('Y') }} {{ $tenant->name }}. {{ $locale === 'ar' ? 'جميع الحقوق محفوظة.' : 'All rights reserved.' }}
        </p>
        <p class="text-xs text-white/50">
            {{ $locale === 'ar' ? 'مدعوم بواسطة محاسبي' : 'Powered by Muhasebi' }}
        </p>
    </div>
</footer>
