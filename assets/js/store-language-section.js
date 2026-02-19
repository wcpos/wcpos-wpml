(function () {
  function registerLanguageSection() {
    if (!window.wcpos || !window.wcpos.storeEdit || typeof window.wcpos.storeEdit.registerSection !== 'function') {
      setTimeout(registerLanguageSection, 40);
      return;
    }

    var config = window.wcposWPMLStoreEdit || {};
    var defaultLanguage = config.defaultLanguage || '';
    var languages = Array.isArray(config.languages) ? config.languages : [];
    var strings = config.strings || {};

    if (languages.length === 0) {
      return;
    }

    var sectionLabel = strings.sectionLabel || 'Language';
    var title = strings.title || sectionLabel;
    var description = strings.description || '';
    var help = strings.help || '';
    var defaultOption = strings.defaultOption || 'Default language';
    var noLanguages = strings.noLanguages || 'No WPML languages found.';

    if (window.wcpos.storeEdit.getSections && window.wcpos.storeEdit.getSections().has('wcpos-wpml-language')) {
      return;
    }

    var el = window.wp && window.wp.element ? window.wp.element.createElement : null;

    if (!el) {
      return;
    }

    function LanguageSection(props) {
      var value = props.store.language || '';

      return el(
        'div',
        { className: 'wcpos:rounded-lg wcpos:border wcpos:border-gray-200 wcpos:bg-white wcpos:p-6' },
        el('div', { className: 'wcpos:mb-4' },
          el('h3', { className: 'wcpos:text-base wcpos:font-semibold wcpos:text-gray-900 wcpos:m-0' }, title),
          description ? el('p', { className: 'wcpos:mt-1 wcpos:text-sm wcpos:text-gray-500' }, description) : null
        ),
        languages.length > 0
          ? el(
            'select',
            {
              className: 'wcpos:block wcpos:w-full wcpos:rounded-md wcpos:border wcpos:border-gray-300 wcpos:px-2.5 wcpos:py-1.5 wcpos:text-sm wcpos:shadow-xs wcpos:focus:outline-none wcpos:focus:ring-2 wcpos:focus:ring-wp-admin-theme-color wcpos:focus:border-wp-admin-theme-color',
              value: value,
              onChange: function (event) {
                props.onChange('language', event.target.value);
              }
            },
            el('option', { value: '' }, defaultOption),
            languages.map(function (language) {
              return el('option', { key: language.value, value: language.value }, language.label);
            })
          )
          : el('p', { className: 'wcpos:text-sm wcpos:text-gray-500' }, noLanguages),
        help ? el('p', { className: 'wcpos:mt-3 wcpos:text-xs wcpos:text-gray-500' }, help) : null
      );
    }

    window.wcpos.storeEdit.registerSection('wcpos-wpml-language', {
      component: LanguageSection,
      label: sectionLabel,
      column: 'sidebar',
      priority: 32
    });
  }

  registerLanguageSection();
})();
