/**
 * Alpine.js komponenta pro správu vazeb produktu
 * Používá se v záložce "Vazby" v ProductForm
 */
function productRelations(config) {
	return {
		// Konfigurace
		productUuid: config.productUuid,
		apiGetUrl: config.apiGetUrl,
		apiSaveUrl: config.apiSaveUrl,
		productsSearchUrl: config.productsSearchUrl,
		checkImageUrl: config.checkImageUrl,
		bulkAddUrl: config.bulkAddUrl,
		saveTagsUrl: config.saveTagsUrl,

		// Stav
		loading: true,
		saving: false,
		error: null,
		lastSaved: null,

		// Related Tags stav
		relatedTags: '',
		tagsSaving: false,
		tagsLastSaved: null,
		tagsPendingChanges: false,

		// Data
		relatedTypes: [],
		relations: {},
		producers: {},
		expandedTypes: {},

		// Auto-save
		saveTimeout: null,
		pendingChanges: false,
		initializing: true,

		// Bulk import
		bulkInput: {},
		bulkText: {},
		bulkProducer: {},
		bulkProcessing: {},
		bulkResult: {},

		// Čas pro dynamickou aktualizaci
		currentTime: Date.now(),
		timeInterval: null,

		// Klíč pro vynucení re-renderu
		reloadKey: 0,

		/**
		 * Inicializace komponenty - načte data z API
		 */
		async init() {
			await this.loadRelations();

			// Aktualizuj čas každých 10 sekund pro dynamický "před X min"
			this.timeInterval = setInterval(() => {
				this.currentTime = Date.now();
			}, 10000);

			// Po krátké chvíli (po inicializaci Select2) povolit auto-save
			setTimeout(() => {
				this.initializing = false;
			}, 500);
		},

		/**
		 * Načte vazby produktu z API
		 */
		async loadRelations(silent = false) {
			if (!silent) {
				this.loading = true;
			}
			this.error = null;

			try {
				const response = await fetch(this.apiGetUrl);
				const data = await response.json();

				if (data.error) {
					this.error = data.error;
					return;
				}

				this.relatedTypes = data.relatedTypes || [];
				// Aktualizovat relations in-place pro zachování Alpine reaktivity
				Object.keys(this.relations).forEach(key => delete this.relations[key]);
				Object.assign(this.relations, data.relations || {});
				this.producers = data.producers || {};
				this.relatedTags = data.relatedTags || '';
			} catch (e) {
				this.error = 'Chyba při načítání: ' + e.message;
				console.error('ProductRelations load error:', e);
			} finally {
				if (!silent) {
					this.loading = false;
				}
			}
		},

		/**
		 * Uloží vazby produktu přes API
		 */
		async save() {
			if (this.saving) return;

			this.saving = true;
			this.error = null;

			try {
				const response = await fetch(this.apiSaveUrl, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						productUuid: this.productUuid,
						relations: this.relations,
					}),
				});

				const data = await response.json();

				if (data.error) {
					this.error = data.error;
					if (typeof toastr !== 'undefined') {
						toastr.error('Chyba při ukládání: ' + data.error);
					}
					return;
				}

				this.lastSaved = new Date();
				this.pendingChanges = false;

				if (typeof toastr !== 'undefined') {
					toastr.success('Vazby uloženy');
				}
			} catch (e) {
				this.error = 'Chyba při ukládání: ' + e.message;
				console.error('ProductRelations save error:', e);
				if (typeof toastr !== 'undefined') {
					toastr.error('Chyba při ukládání');
				}
			} finally {
				this.saving = false;
			}
		},

		/**
		 * Auto-save s debounce 500ms
		 */
		autoSave() {
			// Nespouštět auto-save během inicializace
			if (this.initializing) {
				return;
			}

			this.pendingChanges = true;

			if (this.saveTimeout) {
				clearTimeout(this.saveTimeout);
			}

			this.saveTimeout = setTimeout(() => {
				this.save();
			}, 500);
		},

		/**
		 * Přepne rozbalení typu vazby
		 */
		toggleType(typeUuid) {
			this.expandedTypes[typeUuid] = !this.expandedTypes[typeUuid];
		},

		/**
		 * Vrátí počet vazeb pro daný typ
		 */
		getRelationCount(typeUuid) {
			const rel = this.relations[typeUuid];
			if (!rel) return 0;
			return (rel.master?.length || 0) + (rel.slave?.length || 0);
		},

		/**
		 * Přidá nový řádek vazby (bez auto-save, řádek je prázdný)
		 */
		addRow(typeUuid, side) {
			if (!this.relations[typeUuid]) {
				this.relations[typeUuid] = { master: [], slave: [] };
			}

			const type = this.relatedTypes.find(t => t.uuid === typeUuid);
			const currentLength = this.relations[typeUuid][side]?.length || 0;

			if (side === 'master') {
				this.relations[typeUuid].master.push({
					slaveUuid: null,
					slaveName: '',
					slaveProducer: null,
					amount: type?.defaultAmount || 1,
					priority: (currentLength + 1) * 10,
					hidden: false,
					discountPct: null,
					masterPct: null,
					productName: null,
					productCode: null,
					imageName: null,
					imageExists: false,
				});
			} else {
				this.relations[typeUuid].slave.push({
					masterUuid: null,
					amount: type?.defaultAmount || 1,
					priority: (currentLength + 1) * 10,
					hidden: false,
					discountPct: null,
					masterPct: null,
					productName: null,
					productCode: null,
				});
			}
			// Nevolat autoSave - prázdný řádek se neuloží
		},

		/**
		 * Odstraní řádek vazby
		 */
		removeRow(typeUuid, side, index) {
			if (this.relations[typeUuid] && this.relations[typeUuid][side]) {
				this.relations[typeUuid][side].splice(index, 1);
				this.autoSave();
			}
		},

		/**
		 * Zpracuje hromadný import vazeb
		 */
		async processBulkImport(typeUuid) {
			console.log('processBulkImport called', typeUuid, this.bulkText);
			const text = this.bulkText[typeUuid]?.trim();
			console.log('text:', text);
			if (!text) {
				console.log('No text, returning');
				return;
			}

			const lines = text.split('\n').map(line => line.trim()).filter(line => line !== '');
			if (lines.length === 0) return;

			// Použít spread operator pro reaktivitu
			this.bulkProcessing = { ...this.bulkProcessing, [typeUuid]: true };
			this.bulkResult = { ...this.bulkResult, [typeUuid]: null };

			try {
				const response = await fetch(this.bulkAddUrl, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						productUuid: this.productUuid,
						typeUuid: typeUuid,
						producerUuid: this.bulkProducer[typeUuid] || '',
						lines: lines,
					}),
				});

				const data = await response.json();
				console.log('Bulk import response:', data);
				console.log('data.added:', data.added);
				console.log('data.added.length:', data.added?.length);

				if (data.error) {
					console.log('Error in response:', data.error);
					this.bulkProcessing = { ...this.bulkProcessing, [typeUuid]: false };
					if (typeof toastr !== 'undefined') {
						toastr.error('Chyba: ' + data.error);
					}
					return;
				}

				this.bulkResult = { ...this.bulkResult, [typeUuid]: data };

				// Vyčistit input
				this.bulkText[typeUuid] = '';

				console.log('=== BULK IMPORT DEBUG ===');
				console.log('Response data:', JSON.stringify(data, null, 2));
				console.log('data.added:', data.added);
				console.log('data.added?.length:', data.added?.length);
				console.log('Condition result:', data.added && data.added.length > 0);
				console.log('=========================');
				// Refresh komponenty po úspěšném importu
				if (data.added && data.added.length > 0) {
					console.log('Will refresh component!');
					if (typeof toastr !== 'undefined') {
						toastr.success(`Přidáno ${data.added.length} vazeb`);
					}

					// Zavřít bulk import panel
					this.bulkInput[typeUuid] = false;
					this.bulkResult[typeUuid] = null;

					// Načíst nová data z API
					await this.loadRelations();

					// Force re-render komponenty
					this.reloadKey++;

					console.log('Component refreshed!');
					return;
				} else if (data.skipped && data.skipped.length > 0) {
					console.log('All skipped');
					if (typeof toastr !== 'undefined') {
						toastr.warning(`Všechny položky byly přeskočeny`);
					}
				} else {
					console.log('No added, no skipped');
				}
			} catch (e) {
				console.error('Bulk import error:', e);
				if (typeof toastr !== 'undefined') {
					toastr.error('Chyba při importu');
				}
			} finally {
				this.bulkProcessing = { ...this.bulkProcessing, [typeUuid]: false };
			}
		},

		/**
		 * Inicializuje Select2 pro výběr výrobce v hromadném importu
		 */
		initBulkProducerSelect2(el, typeUuid) {
			const self = this;

			$(el).select2({
				placeholder: '-- Bez výrobce --',
				allowClear: true,
				width: '100%',
			}).on('change', function(e) {
				self.bulkProducer[typeUuid] = e.target.value || '';
			});
		},

		/**
		 * Inicializuje Select2 pro výběr výrobce v řádku textové vazby
		 */
		initRowProducerSelect2(el, typeUuid, side, index) {
			const self = this;

			// Naplnit options z producers
			$(el).empty().append('<option value="">-- Výrobce --</option>');
			for (const [uuid, name] of Object.entries(this.producers)) {
				$(el).append(new Option(name, uuid, false, false));
			}

			// Nastavit výchozí hodnotu před inicializací Select2
			const relation = this.relations[typeUuid][side][index];
			if (relation.slaveProducer) {
				$(el).val(relation.slaveProducer);
			}

			$(el).select2({
				placeholder: '-- Výrobce --',
				allowClear: true,
				width: '100%',
			}).on('change', function(e) {
				if (self.initializing) return;

				self.relations[typeUuid][side][index].slaveProducer = e.target.value || null;
				self.autoSave();
			});
		},

		/**
		 * Inicializuje Select2 na elementu pro vyhledávání produktů
		 */
		initSelect2(el, typeUuid, side, index) {
			const self = this;

			$(el).select2({
				ajax: {
					url: this.productsSearchUrl,
					dataType: 'json',
					delay: 250,
					data: function(params) {
						return {
							q: params.term,
						};
					},
					processResults: function(data) {
						return {
							results: data.results || data,
						};
					},
					cache: true,
				},
				minimumInputLength: 2,
				placeholder: 'Hledat produkt...',
				allowClear: true,
				width: '100%',
			}).on('change', function(e) {
				const selectedData = $(this).select2('data')[0];

				if (side === 'master') {
					self.relations[typeUuid].master[index].slaveUuid = e.target.value || null;
					self.relations[typeUuid].master[index].productName = selectedData?.text || null;
					self.relations[typeUuid].master[index].productCode = selectedData?.code || null;
					// Vyčistit textové pole pokud je vybrán produkt
					if (e.target.value) {
						self.relations[typeUuid].master[index].slaveName = '';
						self.relations[typeUuid].master[index].slaveProducer = null;
					}
				} else {
					self.relations[typeUuid].slave[index].masterUuid = e.target.value || null;
					self.relations[typeUuid].slave[index].productName = selectedData?.text || null;
					self.relations[typeUuid].slave[index].productCode = selectedData?.code || null;
				}

				self.autoSave();
			});

			// Nastavit výchozí hodnotu pokud existuje
			const relation = this.relations[typeUuid][side][index];
			const productUuid = side === 'master' ? relation.slaveUuid : relation.masterUuid;
			const productName = relation.productName;
			const productCode = relation.productCode;

			if (productUuid && productName) {
				const displayText = productCode ? `${productName} (${productCode})` : productName;
				const option = new Option(displayText, productUuid, true, true);
				// Použít change.select2 místo change - aktualizuje jen UI, nespustí náš handler
				$(el).append(option).trigger('change.select2');
			}
		},

		/**
		 * Generuje očekávaný název obrázku z názvu vazby
		 */
		generateImageName(slaveName) {
			if (!slaveName) return null;
			// Jednoduchá webalize - lowercase, replace spaces/special chars
			return slaveName
				.toLowerCase()
				.normalize('NFD')
				.replace(/[\u0300-\u036f]/g, '')
				.replace(/[^a-z0-9]+/g, '-')
				.replace(/^-+|-+$/g, '') + '.jpg';
		},

		/**
		 * Aktualizuje očekávaný název obrázku při změně slaveName a kontroluje existenci
		 */
		async updateImageName(typeUuid, index) {
			const row = this.relations[typeUuid].master[index];
			if (row && !row.slaveUuid && row.slaveName) {
				const imageName = this.generateImageName(row.slaveName);
				row.imageName = imageName;

				// Zkontrolovat existenci obrázku přes API
				try {
					const url = this.checkImageUrl.replace('__IMAGE_NAME__', encodeURIComponent(imageName));
					const response = await fetch(url);
					const data = await response.json();
					row.imageExists = data.exists;
				} catch (e) {
					console.error('Error checking image:', e);
					row.imageExists = false;
				}
			} else if (row) {
				row.imageName = null;
				row.imageExists = false;
			}
		},

		/**
		 * Formátuje čas posledního uložení (používá currentTime pro reaktivitu)
		 */
		formatLastSaved() {
			if (!this.lastSaved) return '';

			// Použij currentTime pro reaktivní aktualizaci
			const diff = Math.floor((this.currentTime - this.lastSaved.getTime()) / 1000);

			if (diff < 5) return 'právě teď';
			if (diff < 60) return `před ${diff}s`;

			const minutes = Math.floor(diff / 60);
			if (minutes < 60) return `před ${minutes} min`;

			const hours = Math.floor(minutes / 60);
			return `před ${hours} hod`;
		},

		/**
		 * Uloží relatedTags
		 */
		async saveRelatedTags() {
			this.tagsPendingChanges = true;
			this.tagsSaving = true;

			try {
				const response = await fetch(this.saveTagsUrl, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						productUuid: this.productUuid,
						relatedTags: this.relatedTags,
					}),
				});

				const data = await response.json();

				if (data.error) {
					if (typeof toastr !== 'undefined') {
						toastr.error('Chyba při ukládání tagů: ' + data.error);
					}
					return;
				}

				this.tagsLastSaved = new Date();
				this.tagsPendingChanges = false;
			} catch (e) {
				console.error('Save tags error:', e);
				if (typeof toastr !== 'undefined') {
					toastr.error('Chyba při ukládání tagů');
				}
			} finally {
				this.tagsSaving = false;
			}
		},

		/**
		 * Formátuje čas posledního uložení tagů
		 */
		formatTagsLastSaved() {
			if (!this.tagsLastSaved) return '';

			const diff = Math.floor((this.currentTime - this.tagsLastSaved.getTime()) / 1000);

			if (diff < 5) return 'právě teď';
			if (diff < 60) return `před ${diff}s`;

			const minutes = Math.floor(diff / 60);
			if (minutes < 60) return `před ${minutes} min`;

			const hours = Math.floor(minutes / 60);
			return `před ${hours} hod`;
		},
	};
}
