=== HHG for TranslatePress ===
Contributors: gwuluo, jayden778
Donate link: https://huhonggang.com/hhg-for-translatepress/
Author: huhonggang
Author URI: https://huhonggang.com/
Tags: translatepress, translation, openai, gemini, multilingual
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.4
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add AI translation engines (Gemini, Hunyuan, OpenAI, GLM) to TranslatePress with multi-model support and beautiful interface.

== Description ==

We recommend using Tencent Hunyuan and Zhipu AI.  - 2025/12/12

HHG for TranslatePress is an enhancement plugin for TranslatePress multi-language plugin to extend the automatic translation capability, integrating mainstream AI translation engines such as Google Gemini, Tencent Hunyuan, OpenAI GPT, Wisdom Spectrum AI GLM, etc., and supporting multi-model selection, API customization, batch translation, error handling and Advanced language mapping. The interface style is consistent with TranslatePress natively and has strong compatibility, which is suitable for sites that need high-quality automatic translation.

**Main Features:**
- Support Google Gemini, Tencent Hunyuan, OpenAI GPT, Smart Spectrum AI GLM AI translation engines.
- Supports multiple model selection and customized models
- Supports custom API Endpoint
- Fully compatible with TranslatePress native settings and front-end translation.
- Beautiful interface and convenient operation
- Supports batch translation, error alerts, and API testing.
- Code security, in line with WordPress best practices

== External Services ==

This plugin connects to external AI translation services to provide automatic translation functionality. The following third-party services are used:

**Google Gemini AI**
- Service: Google AI Studio API (https://aistudio.google.com/)
- Purpose: Provides AI-powered text translation using Google's Gemini models
- Data sent: Text content to be translated, source and target language codes
- When: Only when Gemini is selected as translation engine and translation is requested
- Privacy Policy: https://policies.google.com/privacy
- Terms of Service: https://policies.google.com/terms

**OpenAI GPT**
- Service: OpenAI API (https://api.openai.com/)
- Purpose: Provides AI-powered text translation using OpenAI's GPT models
- Data sent: Text content to be translated, source and target language codes
- When: Only when OpenAI is selected as translation engine and translation is requested
- Privacy Policy: https://openai.com/privacy/
- Terms of Service: https://openai.com/terms/

**Tencent Hunyuan**
- Service: Tencent Cloud Hunyuan API (https://hunyuan.tencentcloudapi.com/)
- Purpose: Provides AI-powered text translation using Tencent's Hunyuan models
- Data sent: Text content to be translated, source and target language codes
- When: Only when Hunyuan is selected as translation engine and translation is requested
- Privacy Policy: https://intl.cloud.tencent.com/document/product/301/17345
- Terms of Service: https://intl.cloud.tencent.com/document/product/301/9248

**ZhiPu AI GLM**
- Service: ZhiPu AI API (https://bigmodel.cn/)
- Purpose: Provides AI-powered text translation using ZhiPu's GLM models
- Data sent: Text content to be translated, source and target language codes
- When: Only when ZhiPu is selected as translation engine and translation is requested
- Privacy Policy: https://www.zhipuai.cn/privacy
- Terms of Service: https://www.zhipuai.cn/terms

**Important Notes:**
- No data is sent to external services unless you explicitly configure and use one of these translation engines
- All API communications are made over secure HTTPS connections
- No personal user data is collected or transmitted - only the text content you choose to translate
- You are responsible for complying with the terms of service and privacy policies of the services you choose to use


== Installation ==

1. Make sure the [TranslatePress](https://wordpress.org/plugins/translatepress-multilingual/) plugin is installed and activated.
2. Upload the plugin to the `/wp-content/plugins/hhg-for-translatepress/` directory or upload the zip directly in the backend.
3. Enable HHG for TranslatePress on the Plugins page in the WordPress backend.
4. Go to “Settings > TranslatePress > Automatic Translation” page, select and configure the desired AI translation engine and model, and save the settings.



== Frequently Asked Questions ==

=What model is recommended? =
Tencent Hunyuan and Zhipu AI are both professional translation AI models.

=Select no new model? =
You must have the TranslatePress - Multilingual plugin enabled, and some of them require the TranslatePress - Business extension to work.

= Do I need the TranslatePress master plugin? = 
Yes, TranslatePress must be installed and activated first.

= What AI translation engines are supported? = 
currently supports Google Gemini, Tencent Hunyuan, OpenAI GPT, and Smart Spectrum AI GLM, and will continue to expand.

= How to get an API Key? = 
Please go to the respective platforms (Google AI Studio, Tencent Hunyuan, OpenAI, Wisdom Spectrum AI Open Platform) to apply for an API Key and fill it in the setup page.

= Do you support custom models and API addresses? = 
Yes, OpenAI and Gemini can customize models, and OpenAI supports custom API Endpoint.

= Is the plugin secure? = 
All inputs and outputs are securely handled by WordPress and comply with the official security specification.

== Screenshots ==

1. Setup screen: Multi-Engine Selection and Model Configuration
2. OpenAI configuration example
3. Tencent Hunyuan Configuration Example


== Upgrade Notice ==
* uptodate


== Changelog ==

= 1.0.4 =
* Zhipu GLM: Fix fallback to mtapi by overriding engine class mapping and forcing availability when automatic translation is enabled
* Zhipu GLM: Use `source_lang=auto`, standardize target language codes (zh_CN→zh-CN, zh_TW→zh-TW, en_US→en, ru_RU→ru)
* Zhipu GLM: Response parser compatible with `choices[0].message.content.text` and `choices[0].messages[0].content.text`
* Zhipu GLM: Add second-pass retry for missing items in a batch; only cache full-success batches to avoid partial-cache issues
* Zhipu GLM: Add HTTP/2, gzip, TCP_NODELAY in HTTP client; add detailed logs and transient caching with safe keys
* Zhipu GLM: Remove social_literature_translation_agent option; always use general_translation agent for broad language support
* OpenAI GPT: Parallelize batch requests, add strict system/user prompt, second-pass retry for missing items, log responses, count characters
* Tencent Hunyuan: Switch to ChatTranslations API; implement TC3-HMAC-SHA256 signature and proper headers; support models hunyuan-translation(-lite)
* Tencent Hunyuan: Parse both `Response.Choices[0].Message` and `Response.TargetText`; propagate errors from `Response.Error` instead of generic format error
* Tencent Hunyuan: Limit concurrency to 5 by batching; improve language mapping (zh_TW→zh-TR, zh_CN→zh, en_US→en, ru_RU→ru)
* TranslatePress quota: Integrate character counting and logging for Zhipu and Hunyuan so daily limits and "Today's Character Count" work correctly
* Stability: Fix direct write to protected TP property causing fatal error; rely on filters and class mapping instead
* Stability: Fix missing includes paths for logger/cache; add existence checks to avoid fatal on load

= 1.0.3 =
* Fix some functions.
* Remove business force loading.

= 1.0.2 =
* Add Wisdom Spectrum AI GLM support, support GLM-4-FlashX, GLM-4-Flash-250414 and other models.
* Fix the problem of getting the API key of Wisdom Spectrum, make sure the settings are saved correctly.
* Fix the "Undefined array key" warning in front-end translation, and optimize the array key matching.
* Optimize API call parameters according to official documents, and add concurrency support.
* Optimize the interface style, and unify the interface of all engine settings.
* Improve error handling and API test function.
* Code security reinforcement, in line with WordPress security specification.

= 1.0.1 =
* New OpenAI support, support for custom models and API Endpoint
* Optimize the interface and compatibility of Gemini and Hunyuan.
* Fix the problems of setting saving, API testing, batch translation, etc.
* Code security enhancement, remove unused files.

= 1.0.0 =
* First version, support Gemini, Tencent Hunyuan automatic translation.
* Recommended upgrade, improve compatibility and security, add OpenAI support.


== License ==

GPLv2 or later

