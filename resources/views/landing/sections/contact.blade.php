<section id="contact" class="py-20 px-6">
    <div class="max-w-4xl mx-auto">
        <h2 class="text-3xl md:text-4xl font-bold text-primary text-center mb-6">
            {{ $locale === 'ar' ? 'تواصل معنا' : 'Contact Us' }}
        </h2>
        <div class="w-20 h-1 bg-secondary mx-auto mb-12 rounded"></div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-12">
            <div class="space-y-6">
                @if($tenant->email)
                    <div class="flex items-start gap-4">
                        <div class="w-10 h-10 bg-primary/10 rounded-lg flex items-center justify-center flex-shrink-0">
                            <span class="text-primary font-bold">@</span>
                        </div>
                        <div>
                            <h4 class="font-bold text-gray-800 mb-1">{{ $locale === 'ar' ? 'البريد الإلكتروني' : 'Email' }}</h4>
                            <a href="mailto:{{ $tenant->email }}" class="text-secondary hover:underline">{{ $tenant->email }}</a>
                        </div>
                    </div>
                @endif

                @if($tenant->phone)
                    <div class="flex items-start gap-4">
                        <div class="w-10 h-10 bg-primary/10 rounded-lg flex items-center justify-center flex-shrink-0">
                            <span class="text-primary font-bold text-lg">&#9742;</span>
                        </div>
                        <div>
                            <h4 class="font-bold text-gray-800 mb-1">{{ $locale === 'ar' ? 'الهاتف' : 'Phone' }}</h4>
                            <a href="tel:{{ $tenant->phone }}" class="text-secondary hover:underline" dir="ltr">{{ $tenant->phone }}</a>
                        </div>
                    </div>
                @endif

                @if($tenant->address || $tenant->city)
                    <div class="flex items-start gap-4">
                        <div class="w-10 h-10 bg-primary/10 rounded-lg flex items-center justify-center flex-shrink-0">
                            <span class="text-primary font-bold text-lg">&#9906;</span>
                        </div>
                        <div>
                            <h4 class="font-bold text-gray-800 mb-1">{{ $locale === 'ar' ? 'العنوان' : 'Address' }}</h4>
                            <p class="text-gray-600">
                                {{ $tenant->address }}{{ $tenant->address && $tenant->city ? '، ' : '' }}{{ $tenant->city }}
                            </p>
                        </div>
                    </div>
                @endif
            </div>

            <div class="bg-gray-50 rounded-xl p-8 border border-gray-100">
                <h4 class="font-bold text-primary mb-2">{{ $locale === 'ar' ? 'ساعات العمل' : 'Working Hours' }}</h4>
                <p class="text-gray-600 mb-4">
                    {{ $locale === 'ar' ? 'الأحد - الخميس: 9:00 ص - 5:00 م' : 'Sunday - Thursday: 9:00 AM - 5:00 PM' }}
                </p>
                @if($tenant->tax_id)
                    <h4 class="font-bold text-primary mb-2">{{ $locale === 'ar' ? 'رقم التسجيل الضريبي' : 'Tax Registration' }}</h4>
                    <p class="text-gray-600" dir="ltr">{{ $tenant->tax_id }}</p>
                @endif
            </div>
        </div>
    </div>
</section>
