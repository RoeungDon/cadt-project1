<template>
  <article class="contract">
    <!-- Page 1: header, parties -->
    <header class="kingdom">
      <div class="k1">ព្រះរាជាណាចក្រកម្ពុជា</div>
      <div class="k2">ជាតិ សាសនា ព្រះមហាក្សត្រ</div>
      <svg class="ornament" viewBox="0 0 120 12" aria-hidden="true">
        <path d="M2 6h44M74 6h44" stroke="currentColor" stroke-width="1" />
        <path d="M60 1l5 5-5 5-5-5z M50 6l3-3 3 3-3 3z M64 6l3-3 3 3-3 3z" fill="currentColor" />
      </svg>
    </header>

    <h1 class="title">កិច្ចសន្យាជួលបន្ទប់ស្នាក់នៅ</h1>
    <p class="number">លេខ៖ {{ khDigits(c.number) }}</p>

    <p class="lead">
      កិច្ចសន្យានេះ ធ្វើឡើងនៅ{{ khDate(c.start_date) }} រវាងភាគីដូចខាងក្រោម៖
    </p>

    <section class="party">
      <h3>ភាគី «ក» (អ្នកឱ្យជួល)</h3>
      <p>
        {{ landlordTitle }} <b><Fill :v="c.landlord.name" w="45mm" /></b>
        ភេទ <Fill :v="GENDER_KM[c.landlord.gender]" w="12mm" />
        សញ្ជាតិ <Fill :v="c.landlord.nationality" w="18mm" />
        កាន់អត្តសញ្ញាណប័ណ្ណ/លិខិតឆ្លងដែនលេខ <Fill :v="c.landlord.id_card" w="35mm" />
        ទូរស័ព្ទ <Fill :v="khDigits(c.landlord.phone)" w="30mm" />
        ជាម្ចាស់ ឬអ្នកគ្រប់គ្រងស្របច្បាប់នៃអគារស្នាក់នៅ ស្ថិតនៅ <Fill :v="c.property_address" w="80mm" />។
      </p>
    </section>

    <section class="party">
      <h3>ភាគី «ខ» (អ្នកជួល)</h3>
      <p>
        ឈ្មោះ <b><Fill :v="c.tenant.name" w="45mm" /></b>
        ភេទ <Fill :v="GENDER_KM[c.tenant.gender]" w="12mm" />
        សញ្ជាតិ <Fill :v="c.tenant.nationality" w="18mm" />
        កាន់អត្តសញ្ញាណប័ណ្ណ/លិខិតឆ្លងដែនលេខ <Fill :v="c.tenant.id_card" w="35mm" />
        ទូរស័ព្ទ <Fill :v="khDigits(c.tenant.phone)" w="30mm" />
        អាសយដ្ឋានអចិន្ត្រៃយ៍ <Fill :v="c.tenant.address" w="80mm" />។
        អ្នកទំនាក់ទំនងពេលអាសន្ន <Fill :v="c.tenant.emergency_name" w="40mm" />
        ទូរស័ព្ទ <Fill :v="khDigits(c.tenant.emergency_phone)" w="30mm" />។
      </p>
    </section>

    <p>
      ភាគីទាំងពីរ មានសមត្ថភាពពេញលេញតាមច្បាប់ ហើយដោយស្ម័គ្រចិត្ត បានព្រមព្រៀងគ្នាធ្វើកិច្ចសន្យាជួលនេះ
      ដោយផ្អែកលើក្រមរដ្ឋប្បវេណីនៃព្រះរាជាណាចក្រកម្ពុជា ជំពូកស្តីពីការជួល (មាត្រា ៥៩៦ ដល់មាត្រា ៦២៤) តាមប្រការដូចខាងក្រោម៖
    </p>

    <!-- Articles -->
    <section class="article">
      <h2>ប្រការ ១៖ កម្មវត្ថុនៃការជួល</h2>
      <p>១.១ ភាគី «ក» យល់ព្រមឱ្យភាគី «ខ» ជួលបន្ទប់លេខ <b>{{ khDigits(c.room.number) }}</b><template v-if="c.room.floor != null"> {{ Number(c.room.floor) === 0 ? 'ជាន់ផ្ទាល់ដី' : `ជាន់ទី${khDigits(c.room.floor)}` }}</template><template v-if="c.room.type"> ប្រភេទ {{ c.room.type }}</template> ក្នុងអគារខាងលើ សម្រាប់តែគោលបំណងស្នាក់នៅប៉ុណ្ណោះ។</p>
      <p>១.២ សម្ភារៈ និងបរិក្ខារក្នុងបន្ទប់ ត្រូវកត់ត្រាក្នុងឧបសម្ព័ន្ធ ២ (បញ្ជីប្រគល់-ទទួលបន្ទប់) ដែលជាផ្នែកមិនអាចកាត់ផ្តាច់បាននៃកិច្ចសន្យានេះ។</p>
      <p v-if="c.occupants.length">
        ១.៣ អ្នកស្នាក់នៅជាមួយភាគី «ខ» មានចំនួន {{ khDigits(c.occupants.length) }} នាក់៖
        <template v-for="(person, i) in c.occupants" :key="i">{{ i ? ', ' : '' }}{{ person.name }}<template v-if="person.relationship"> ({{ person.relationship }})</template></template>។
        ការបន្ថែមអ្នកស្នាក់នៅ ត្រូវជូនដំណឹង និងទទួលការយល់ព្រមពីភាគី «ក» ជាមុន។
      </p>
      <p v-else>១.៣ ភាគី «ខ» ស្នាក់នៅម្នាក់ឯង។ ការបន្ថែមអ្នកស្នាក់នៅ ត្រូវជូនដំណឹង និងទទួលការយល់ព្រមពីភាគី «ក» ជាមុន។</p>
    </section>

    <section class="article">
      <h2>ប្រការ ២៖ រយៈពេលជួល</h2>
      <p>២.១ រយៈពេលជួល <b>{{ khDigits(c.term_months) }} ខែ</b> ចាប់ពី{{ khDate(c.start_date) }} ដល់{{ khDate(c.end_date) }}។</p>
      <p>២.២ ពេលផុតកំណត់ បើភាគីណាមួយមិនបានជូនដំណឹងជាលាយលក្ខណ៍អក្សរ មុនយ៉ាងតិច {{ khDigits(c.notice_days) }} ថ្ងៃ កិច្ចសន្យានេះត្រូវបន្តដោយស្វ័យប្រវត្តិ ក្នុងរយៈពេល និងលក្ខខណ្ឌដដែល។</p>
    </section>

    <section class="article">
      <h2>ប្រការ ៣៖ ថ្លៃឈ្នួល និងការទូទាត់</h2>
      <p>៣.១ ថ្លៃឈ្នួលប្រចាំខែ <b>{{ usd(c.room.rent_usd) }}</b> ({{ usdWords(c.room.rent_usd) }})។</p>
      <p>៣.២ ថ្លៃឈ្នួលត្រូវបង់ជាមុន។ នៅចុងខែនីមួយៗ ភាគី «ក» ចេញវិក្កយបត្រមួយ ដែលរួមមានថ្លៃឈ្នួលនៃខែបន្ទាប់ និងថ្លៃទឹក ភ្លើង នៃខែដែលកន្លងទៅ។</p>
      <p>
        ៣.៣ ភាគី «ខ» ត្រូវទូទាត់ក្នុងរយៈពេល {{ khDigits(c.payment_due_days) }} ថ្ងៃ គិតពីថ្ងៃចេញវិក្កយបត្រ ជាសាច់ប្រាក់ ឬផ្ទេរតាម ABA KHQR<template v-if="c.payment_account"> ({{ c.payment_account }})</template>។
        ការបង់ជាប្រាក់រៀល គិតតាមអត្រាប្តូរប្រាក់ដែលបញ្ជាក់ក្នុងវិក្កយបត្រ។
      </p>
      <p>៣.៤ រាល់ការទូទាត់ ត្រូវកត់ត្រាក្នុងប្រព័ន្ធ RTRroom ឬចេញបង្កាន់ដៃ ហើយភាគី «ខ» អាចពិនិត្យបានគ្រប់ពេល។</p>
      <p>៣.៥ ភាគី «ក» មិនដំឡើងថ្លៃឈ្នួលក្នុងរយៈពេលកិច្ចសន្យានេះទេ លើកលែងតែមានការព្រមព្រៀងជាលាយលក្ខណ៍អក្សរពីភាគីទាំងពីរ។</p>
    </section>

    <section class="article">
      <h2>ប្រការ ៤៖ ថ្លៃទឹក និងភ្លើង</h2>
      <p>៤.១ លេខកុងទ័រនៅថ្ងៃចូលស្នាក់៖ ភ្លើង <b>{{ khNumber(c.start_meters.electric, 2) }}</b> kWh និងទឹក <b>{{ khNumber(c.start_meters.water, 2) }}</b> m³។</p>
      <p>៤.២ ភាគី «ក» អានកុងទ័រនៅថ្ងៃទី២៨ នៃខែនីមួយៗ ហើយគិតថ្លៃតាមអត្រាក្នុងឧបសម្ព័ន្ធ ១។ ភាគី «ខ» មានសិទ្ធិចូលរួមពិនិត្យការអានកុងទ័រ។</p>
      <p v-if="c.room.electric_to_edc">
        ៤.៣ បន្ទប់នេះមាននាឡិកាភ្លើងរបស់អគ្គិសនីកម្ពុជា (EDC) ផ្ទាល់<template v-if="c.room.edc_account"> លេខគណនី {{ khDigits(c.room.edc_account) }}</template>។
        ភាគី «ខ» ទទួលខុសត្រូវបង់ថ្លៃភ្លើងតាមវិក្កយបត្រ EDC ដោយផ្ទាល់ ឬតាមរយៈភាគី «ក» ហើយមិនត្រូវទុកឱ្យជំពាក់ឡើយ។
      </p>
      <p>៤.{{ c.room.electric_to_edc ? '៤' : '៣' }} បើអត្រាថ្លៃទឹក ភ្លើង របស់អ្នកផ្គត់ផ្គង់ផ្លាស់ប្តូរ ភាគី «ក» អាចកែអត្រាក្នុងឧបសម្ព័ន្ធ ១ ដោយជូនដំណឹងមុនយ៉ាងតិច ៣០ ថ្ងៃ។</p>
    </section>

    <section class="article">
      <h2>ប្រការ ៥៖ ប្រាក់កក់</h2>
      <p>
        ៥.១ ភាគី «ខ» បានបង់ប្រាក់កក់ចំនួន
        <template v-if="c.deposit_usd != null"><b>{{ usd(c.deposit_usd) }}</b> ({{ usdWords(c.deposit_usd) }})</template>
        <Fill v-else :v="null" w="45mm" />
        ដើម្បីធានាការអនុវត្តកិច្ចសន្យានេះ។ ប្រាក់កក់មិនមែនជាថ្លៃឈ្នួលខែចុងក្រោយ ហើយមិនមានការប្រាក់ទេ។
      </p>
      <p>៥.២ ពេលចាកចេញ ប្រាក់កក់ត្រូវប្រគល់វិញ ក្រោយកាត់៖ ក) វិក្កយបត្រដែលមិនទាន់បង់ ខ) ការខូចខាតលើសពីការរេចរឹលធម្មតា គ) ថ្លៃស្នាក់លើសថ្ងៃ តាមប្រការ ៨.៤។</p>
      <p>៥.៣ ភាគី «ក» ប្រគល់ប្រាក់កក់ដែលនៅសល់ នៅថ្ងៃទូទាត់ចាកចេញ ជាមួយតារាងទូទាត់ប្រាក់កក់ ដែលភាគីទាំងពីរចុះហត្ថលេខា។</p>
    </section>

    <section class="article">
      <h2>ប្រការ ៦៖ សិទ្ធិ និងកាតព្វកិច្ចរបស់ភាគី «ក»</h2>
      <p>៦.១ ប្រគល់បន្ទប់ក្នុងស្ថានភាពប្រើប្រាស់បាន និងធានាឱ្យភាគី «ខ» ប្រើប្រាស់ដោយសុខសាន្ត។</p>
      <p>៦.២ ជួសជុលធំៗ ដែលចាំបាច់សម្រាប់ការប្រើប្រាស់ (រចនាសម្ព័ន្ធ ដំបូល បណ្តាញទឹក និងភ្លើងមេ) តាមមាត្រា ៦០២ នៃក្រមរដ្ឋប្បវេណី លើកលែងការខូចខាតដោយកំហុសរបស់ភាគី «ខ»។</p>
      <p>៦.៣ មិនចូលក្នុងបន្ទប់ ដោយគ្មានការយល់ព្រមពីភាគី «ខ» ឬគ្មានការជូនដំណឹងមុន ២៤ ម៉ោង លើកលែងករណីបន្ទាន់ ដូចជាអគ្គិភ័យ ទឹកលិច ឬគ្រោះថ្នាក់។</p>
      <p>៦.៤ រក្សាការសម្ងាត់ និងការពារទិន្នន័យផ្ទាល់ខ្លួនរបស់ភាគី «ខ» តាមសេចក្តីជូនដំណឹងអំពីឯកជនភាព។</p>
    </section>

    <section class="article">
      <h2>ប្រការ ៧៖ សិទ្ធិ និងកាតព្វកិច្ចរបស់ភាគី «ខ»</h2>
      <p>៧.១ បង់ថ្លៃឈ្នួល ថ្លៃទឹក និងថ្លៃភ្លើង ឱ្យបានទាន់ពេលវេលា។</p>
      <p>៧.២ ថែរក្សាបន្ទប់ និងសម្ភារៈ ដោយយកចិត្តទុកដាក់ដូចអ្នកគ្រប់គ្រងដ៏ប្រុងប្រយ័ត្ន (មាត្រា ៦០១) និងទទួលខុសត្រូវការជួសជុលតូចតាច ដូចជាអំពូលភ្លើង និងក្បាលរ៉ូប៊ីណេ។</p>
      <p>៧.៣ មិនផ្ទេរសិទ្ធិជួល ឬជួលបន្តឱ្យអ្នកដទៃ ដោយគ្មានការយល់ព្រមជាលាយលក្ខណ៍អក្សរពីភាគី «ក» (មាត្រា ៦០៨)។</p>
      <p>៧.៤ មិនកែប្រែ ចោះ វាយ ឬលាបពណ៌បន្ទប់ ដោយគ្មានការអនុញ្ញាតពីភាគី «ក»។</p>
      <p>៧.៥ ហាមរក្សាទុក ប្រើប្រាស់ ឬជួញដូរគ្រឿងញៀន អាវុធ សារធាតុផ្ទុះ ល្បែងស៊ីសង ឬធ្វើសកម្មភាពខុសច្បាប់ណាមួយ។</p>
      <p>៧.៦ គោរពសណ្តាប់ធ្នាប់ អនាម័យ និងសន្តិសុខ ហើយមិនរំខានអ្នកជិតខាង ជាពិសេសចន្លោះម៉ោង ២២:០០ ដល់ ០៦:០០។</p>
      <p>៧.៧ ជូនដំណឹងភាគី «ក» ភ្លាមៗ ពេលមានការខូចខាត ឬបញ្ហា តាមប្រព័ន្ធ RTRroom ឬទូរស័ព្ទ។</p>
      <p>៧.៨ អនុវត្តតាមច្បាប់ និងការណែនាំរបស់អាជ្ញាធរមូលដ្ឋាន ទាក់ទងនឹងការស្នាក់នៅ។</p>
    </section>

    <section class="article">
      <h2>ប្រការ ៨៖ ការបញ្ចប់កិច្ចសន្យា</h2>
      <p>៨.១ បើភាគី «ខ» ចង់ចាកចេញ ត្រូវជូនដំណឹងមុនយ៉ាងតិច {{ khDigits(c.notice_days) }} ថ្ងៃ តាមប្រព័ន្ធ RTRroom ឬជាលាយលក្ខណ៍អក្សរ។ បើជូនដំណឹងគ្រប់ចំនួនថ្ងៃ ថ្លៃឈ្នួលនៃថ្ងៃដែលមិនបានស្នាក់ ត្រូវប្រគល់វិញ។ បើមិនបានជូនដំណឹង ថ្លៃឈ្នួលនោះមិនសងវិញទេ។</p>
      <p>៨.២ ភាគី «ក» អាចបញ្ចប់កិច្ចសន្យា ដោយជូនដំណឹងជាលាយលក្ខណ៍អក្សរមុន {{ khDigits(c.notice_days) }} ថ្ងៃ ប្រសិនបើ៖ ក) ភាគី «ខ» មិនបង់ថ្លៃលើសពី ៣០ ថ្ងៃ ក្រោយថ្ងៃកំណត់ ឬ ខ) ភាគី «ខ» រំលោភប្រការ ៧ ហើយមិនកែតម្រូវក្នុងរយៈពេល ៧ ថ្ងៃ ក្រោយទទួលការព្រមាន។ ករណីរំលោភប្រការ ៧.៥ ភាគី «ក» អាចបញ្ចប់ភ្លាម និងរាយការណ៍ទៅអាជ្ញាធរ។</p>
      <p>៨.៣ ពេលបញ្ចប់ ភាគី «ខ» ត្រូវប្រគល់បន្ទប់ក្នុងស្ថានភាពដើម លើកលែងការរេចរឹលធម្មតា (មាត្រា ៦១៨) ព្រមទាំងប្រគល់សោ និងកាតចូលទាំងអស់។</p>
      <p>៨.៤ បើស្នាក់លើសពីថ្ងៃចាកចេញ គិតថ្លៃតាមថ្ងៃ (ថ្លៃឈ្នួល ÷ ចំនួនថ្ងៃក្នុងខែ) មិនលើសពី {{ khDigits(c.max_overstay_days) }} ថ្ងៃ ហើយកាត់ពីប្រាក់កក់។</p>
    </section>

    <section class="article">
      <h2>ប្រការ ៩៖ ទិន្នន័យផ្ទាល់ខ្លួន និងឯកសារអេឡិចត្រូនិច</h2>
      <p>៩.១ ភាគី «ខ» យល់ព្រមឱ្យភាគី «ក» រក្សាទុក និងប្រើទិន្នន័យផ្ទាល់ខ្លួន ក្នុងប្រព័ន្ធ RTRroom សម្រាប់តែការគ្រប់គ្រងការជួលនេះ តាមសេចក្តីជូនដំណឹងអំពីឯកជនភាព។</p>
      <p>៩.២ កិច្ចសន្យានេះ បង្កើតដោយប្រព័ន្ធ RTRroom ពីទិន្នន័យដែលភាគីទាំងពីរបានផ្តល់។ ច្បាប់ដើមដែលមានហត្ថលេខា និងស្នាមមេដៃ ជាច្បាប់មានអានុភាពគតិយុត្ត។ ច្បាប់ស្កេនដែលរក្សាទុកក្នុងប្រព័ន្ធ ប្រើសម្រាប់យោង ហើយភាគី «ខ» អាចមើលតាមគណនីរបស់ខ្លួន។</p>
      <p>៩.៣ វិក្កយបត្រ បង្កាន់ដៃ និងការជូនដំណឹង ដែលផ្ញើតាមប្រព័ន្ធ RTRroom ត្រូវចាត់ទុកថាភាគីម្ខាងទៀតបានទទួល។</p>
    </section>

    <section class="article">
      <h2>ប្រការ ១០៖ ការដោះស្រាយវិវាទ</h2>
      <p>វិវាទទាំងឡាយ ត្រូវដោះស្រាយជាមុនដោយការចរចាដោយសន្តិវិធី។ បើមិនបានសុខដុមរមនា ភាគីណាមួយអាចស្នើការសម្រុះសម្រួលពីអាជ្ញាធរភូមិ ឃុំ/សង្កាត់ ហើយចុងក្រោយ ពីតុលាការមានសមត្ថកិច្ច។ កិច្ចសន្យានេះស្ថិតក្រោមច្បាប់នៃព្រះរាជាណាចក្រកម្ពុជា។</p>
    </section>

    <section v-if="c.extra_terms.length" class="article">
      <h2>ប្រការ ១១៖ លក្ខខណ្ឌបន្ថែម</h2>
      <p v-for="(term, i) in c.extra_terms" :key="i">១១.{{ khDigits(i + 1) }} {{ term }}</p>
    </section>

    <section class="article">
      <h2>ប្រការ {{ c.extra_terms.length ? '១២' : '១១' }}៖ អវសានប្បញ្ញត្តិ</h2>
      <p>កិច្ចសន្យានេះ មានអានុភាពចាប់ពីថ្ងៃចុះហត្ថលេខា។ ការកែប្រែណាមួយ ត្រូវធ្វើជាលាយលក្ខណ៍អក្សរ និងចុះហត្ថលេខាដោយភាគីទាំងពីរ។ កិច្ចសន្យានេះធ្វើជា ២ ច្បាប់ដើម មានតម្លៃស្មើគ្នា ភាគីនីមួយៗរក្សាទុក ១ ច្បាប់។ ឧបសម្ព័ន្ធ ១ និង ២ ជាផ្នែកមិនអាចកាត់ផ្តាច់បាន។ ភាគីទាំងពីរបានអាន និងយល់ច្បាស់ ហើយចុះហត្ថលេខា និងផ្តិតស្នាមមេដៃ ដោយស្ម័គ្រចិត្ត។</p>
    </section>

    <p class="place">ធ្វើនៅ ................................ {{ khDate(c.start_date) }}</p>

    <section class="signs">
      <div v-for="sign in signs" :key="sign.role" class="sign">
        <div class="sign-role">{{ sign.role }}</div>
        <div class="sign-note">ស្នាមមេដៃ និងហត្ថលេខា</div>
        <div class="sign-box"></div>
        <div class="sign-name">{{ sign.name || '.................................' }}</div>
      </div>
    </section>

    <!-- Annex 1: utility rates -->
    <section class="annex">
      <h2 class="annex-title">ឧបសម្ព័ន្ធ ១៖ អត្រាថ្លៃទឹក និងភ្លើង</h2>
      <p class="annex-sub">ភ្ជាប់ជាមួយកិច្ចសន្យាលេខ {{ khDigits(c.number) }} · បន្ទប់ {{ khDigits(c.room.number) }} · អត្រាប្តូរប្រាក់យោង ១ ដុល្លារ = {{ khNumber(c.exchange_rate) }} រៀល</p>

      <template v-for="u in utilities" :key="u.key">
        <h3>{{ u.label }}</h3>
        <p v-if="u.key === 'electric' && c.room.electric_to_edc">បង់ទៅអគ្គិសនីកម្ពុជា (EDC) តាមវិក្កយបត្រ EDC (ប្រការ ៤.៣)។</p>
        <p v-else-if="u.tariff.method === 'fixed'">ថ្លៃថេរ {{ khNumber(u.tariff.rate) }} រៀល ក្នុងមួយខែ ទោះប្រើច្រើន ឬតិច។</p>
        <p v-else-if="u.tariff.method === 'per_unit'">{{ khNumber(u.tariff.rate) }} រៀល ក្នុងមួយ {{ u.unit }}។</p>
        <template v-else>
          <table class="grid">
            <thead><tr><th>កម្រិតប្រើប្រាស់ ({{ u.unit }}/ខែ)</th><th>អត្រា (រៀល/{{ u.unit }})</th></tr></thead>
            <tbody>
              <tr v-for="(tier, i) in u.tariff.tiers" :key="i">
                <td>{{ tierRange(u.tariff.tiers, i) }}</td>
                <td class="num">{{ khNumber(tier.rate) }}</td>
              </tr>
            </tbody>
          </table>
          <p class="small">
            {{ u.tariff.method === 'progressive' ? 'គិតជាជណ្តើរ៖ ផ្នែកនីមួយៗនៃការប្រើប្រាស់ គិតតាមអត្រានៃកម្រិតរបស់វា។' : 'គិតអត្រាតែមួយ៖ ការប្រើប្រាស់ទាំងអស់ គិតតាមអត្រានៃកម្រិតដែលប្រើសរុបធ្លាក់ចូល។' }}
            <template v-if="u.tariff.min_charge > 0"> ថ្លៃអប្បបរមា {{ khNumber(u.tariff.min_charge) }} រៀល ក្នុងមួយខែ។</template>
          </p>
        </template>
      </template>
    </section>

    <!-- Annex 2: handover list -->
    <section class="annex">
      <h2 class="annex-title">ឧបសម្ព័ន្ធ ២៖ បញ្ជីប្រគល់-ទទួលបន្ទប់</h2>
      <p class="annex-sub">បន្ទប់ {{ khDigits(c.room.number) }} · ថ្ងៃប្រគល់ {{ khDate(c.start_date) }} · កុងទ័រភ្លើង {{ khNumber(c.start_meters.electric, 2) }} kWh · កុងទ័រទឹក {{ khNumber(c.start_meters.water, 2) }} m³ · ចំនួនសោ {{ c.handover?.key_count != null ? khDigits(c.handover.key_count) : '........' }}</p>
      <table class="grid handover">
        <colgroup><col style="width: 8%" /><col style="width: 38%" /><col style="width: 12%" /><col style="width: 20%" /><col style="width: 22%" /></colgroup>
        <thead><tr><th>ល.រ</th><th>សម្ភារៈ / បរិក្ខារ</th><th>ចំនួន</th><th>ស្ថានភាព</th><th>កំណត់សម្គាល់</th></tr></thead>
        <tbody>
          <tr v-for="(item, i) in handoverItems" :key="'i' + i">
            <td class="num">{{ khDigits(i + 1) }}</td>
            <td>{{ item.name }}</td>
            <td class="qty">{{ khDigits(item.quantity) }}</td>
            <td>{{ item.condition }}</td>
            <td>{{ item.note }}</td>
          </tr>
          <tr v-for="n in blankRows" :key="'b' + n"><td class="num">{{ khDigits(handoverItems.length + n) }}</td><td></td><td></td><td></td><td></td></tr>
        </tbody>
      </table>
      <section class="signs two">
        <div class="sign"><div class="sign-role">ភាគី «ខ» ទទួល</div><div class="sign-box small"></div><div class="sign-name">{{ c.tenant.name }}</div></div>
        <div class="sign"><div class="sign-role">ភាគី «ក» ប្រគល់</div><div class="sign-box small"></div><div class="sign-name">{{ c.landlord.name || '.................................' }}</div></div>
      </section>
    </section>
  </article>
</template>

<script setup>
import { computed, h } from 'vue'
import '@/assets/contract.css'
import { GENDER_KM, khDate, khDigits, khNumber, usdWords } from '@/utils/khmer'

const props = defineProps({
  contract: { type: Object, required: true },
})

const c = computed(() => props.contract)

// A value, or a dotted line to write on by hand when it is missing
const Fill = (p) => (p.v ? h('span', p.v) : h('span', { class: 'blank', style: { minWidth: p.w } }))
Fill.props = ['v', 'w']

const landlordTitle = computed(() => (c.value.landlord.gender === 'female' ? 'លោកស្រី' : 'លោក'))

// 120 → "១២០ ដុល្លារអាមេរិក", 85.5 → "៨៥.៥០ ដុល្លារអាមេរិក"
const usd = (value) => `${khNumber(value, Number.isInteger(Number(value)) ? 0 : 2)} ដុល្លារអាមេរិក`

const signs = computed(() => [
  { role: 'ភាគី «ខ» (អ្នកជួល)', name: c.value.tenant.name },
  { role: 'សាក្សី', name: '' },
  { role: 'ភាគី «ក» (អ្នកឱ្យជួល)', name: c.value.landlord.name },
])

// Annex 2: the room's things, then empty rows to write on (at least 3, 12 rows in all when the list is short)
const handoverItems = computed(() => c.value.handover?.items ?? [])
const blankRows = computed(() => Math.max(3, 12 - handoverItems.value.length))

const utilities = computed(() => [
  { key: 'electric', label: 'ក. ភ្លើង', unit: 'kWh', tariff: c.value.utilities.electric },
  { key: 'water', label: 'ខ. ទឹក', unit: 'm³', tariff: c.value.utilities.water },
])

// [{up_to: 10}, {up_to: 50}, {up_to: null}] → "០ – ១០", "លើស ១០ – ៥០", "លើស ៥០"
const tierRange = (tiers, i) => {
  const from = i === 0 ? 0 : tiers[i - 1].up_to
  const to = tiers[i].up_to
  if (to == null) return `លើស ${khNumber(from)}`
  return i === 0 ? `០ – ${khNumber(to)}` : `លើស ${khNumber(from)} – ${khNumber(to)}`
}
</script>

<style scoped>
.contract {
  page: contract;
  color: #000;
  font-family: 'RTR Siemreap', 'Khmer OS Siemreap', 'Khmer OS', sans-serif;
  font-size: 11pt;
  line-height: 1.85;
}
.contract p {
  margin: 0 0 1.5mm;
}
.kingdom {
  text-align: center;
  font-family: 'RTR Muol', 'Khmer OS Muol', serif;
}
.k1 {
  font-size: 13pt;
}
.k2 {
  font-family: 'RTR Muol Light', 'Khmer OS Muol Light', serif;
  font-size: 12pt;
}
.ornament {
  display: block;
  width: 40mm;
  height: 4mm;
  margin: 1mm auto 0;
}
.title {
  margin: 6mm 0 0;
  text-align: center;
  font-family: 'RTR Muol', 'Khmer OS Muol', serif;
  font-size: 16pt;
  font-weight: normal;
}
.number {
  text-align: center;
  margin-bottom: 4mm !important;
}
.party h3,
.annex h3 {
  margin: 2mm 0 0;
  font-family: 'RTR Muol Light', 'Khmer OS Muol Light', serif;
  font-size: 11pt;
  font-weight: normal;
}
.article h2 {
  margin: 3mm 0 1mm;
  font-family: 'RTR Muol Light', 'Khmer OS Muol Light', serif;
  font-size: 11.5pt;
  font-weight: normal;
  break-after: avoid;
}
.article p {
  text-indent: 8mm;
}
.blank {
  display: inline-block;
  border-bottom: 1px dotted #000;
  height: 1em;
  vertical-align: baseline;
}
.place {
  margin-top: 5mm !important;
  text-align: right;
}
.signs {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 6mm;
  margin-top: 3mm;
  text-align: center;
  break-inside: avoid;
}
.signs.two {
  grid-template-columns: 1fr 1fr;
  margin-top: 6mm;
}
.sign-role {
  font-family: 'RTR Muol Light', 'Khmer OS Muol Light', serif;
}
.sign-note {
  font-size: 9.5pt;
}
.sign-box {
  height: 30mm;
}
.sign-box.small {
  height: 22mm;
}
.annex {
  break-before: page;
}
.annex-title {
  margin: 0 0 1mm;
  text-align: center;
  font-family: 'RTR Muol', 'Khmer OS Muol', serif;
  font-size: 13pt;
  font-weight: normal;
}
.annex-sub {
  text-align: center;
  font-size: 10pt;
  margin-bottom: 4mm !important;
}
.grid {
  width: 100%;
  margin: 1mm 0 2mm;
  border-collapse: collapse;
}
.grid th,
.grid td {
  padding: 1mm 2mm;
  border: 1px solid #000;
  text-align: left;
}
.grid th {
  background: #eceef3;
  font-weight: normal;
  -webkit-print-color-adjust: exact;
  print-color-adjust: exact;
}
.grid .num {
  text-align: right;
}
.handover td {
  height: 9mm;
}
.handover .num,
.handover .qty {
  text-align: center;
}
.small {
  font-size: 9.5pt;
}

@media screen and (max-width: 800px) {
  .signs { grid-template-columns: 1fr; }
  .annex { break-before: auto; margin-top: 10mm; }
}
</style>
