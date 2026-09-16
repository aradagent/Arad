    <div class="arf-modal-overlay" id="arfPanelOverlay">
        <div class="arf-card" id="arfCard">
            <button type="button" class="arf-card-close" onclick="arfClosePanel()"><i class="fas fa-times"></i></button>
            <div class="arf-glow"></div>

            <div class="arf-head">
                <div class="arf-head-txt">
                    <div class="arf-head-title">زیرمجموعه‌های شما</div>
                    <div class="arf-head-sub">با دعوت دوستان، بیشتر درآمد کسب کنید</div>
                </div>
                <div class="arf-head-ic"><i class="fas fa-user-friends"></i></div>
            </div>

            <div class="arf-tabs">
                <button type="button" class="arf-tab active" data-arf-tab="users" onclick="arfSwitchTab('users')">
                    <i class="fas fa-users"></i> کاربران
                </button>
                <button type="button" class="arf-tab" data-arf-tab="invite" onclick="arfSwitchTab('invite')">
                    <i class="fas fa-link"></i> دعوت
                </button>
                <button type="button" class="arf-tab" data-arf-tab="stats" onclick="arfSwitchTab('stats')">
                    <i class="fas fa-chart-simple"></i> آمار
                </button>
            </div>

            <!-- ---------- تب کاربران ---------- -->
            <div class="arf-pane active" id="arfPaneUsers">
                <div class="arf-list-head">
                    <span>کاربران دعوت‌شده</span>
                    <span class="arf-count-badge" id="arfCountBadge"><?php echo (int)$__refStats['total_referred']; ?> نفر</span>
                </div>
                <div class="arf-list" id="arfList">
                    <div class="arf-empty">در حال بارگذاری...</div>
                </div>
            </div>

            <!-- ---------- تب دعوت ---------- -->
            <div class="arf-pane" id="arfPaneInvite">
                <div class="arf-invite-hero">
                    <div class="arf-gift-ic"><i class="fas fa-gift"></i></div>
                    <div class="arf-invite-title">دوستان خود را دعوت کنید</div>
                    <div class="arf-invite-sub">با هر دعوت موفق، درآمد و هدیه دریافت کنید</div>
                </div>

                <div class="arf-link-row">
                    <span class="arf-link-ic"><i class="fas fa-link"></i></span>
                    <input type="text" id="arfLinkInput" readonly value="<?php echo htmlspecialchars($__refStats['link']); ?>">
                    <button type="button" id="arfCopyBtn" onclick="arfCopyLink()"><i class="fas fa-copy"></i> کپی لینک</button>
                </div>

                <div class="arf-stats-grid arf-stats-grid-2">
                    <div class="arf-stat">
                        <div class="arf-stat-ic"><i class="fas fa-percentage"></i></div>
                        <div class="arf-stat-v"><?php echo number_format($__refSettings['commission_per_tx_eur'], 2); ?>€</div>
                        <div class="arf-stat-l">پورسانت هر تراکنش</div>
                    </div>
                    <div class="arf-stat">
                        <div class="arf-stat-ic"><i class="fas fa-gift"></i></div>
                        <div class="arf-stat-v"><?php echo number_format($__refSettings['welcome_bonus_eur'], 2); ?>€</div>
                        <div class="arf-stat-l">هدیه‌ی هر دعوت</div>
                    </div>
                </div>

                <div class="arf-howto">
                    <div class="arf-howto-head"><i class="fas fa-lightbulb"></i> نحوه کار:</div>
                    <div class="arf-howto-row"><span class="arf-howto-txt">لینک دعوت خود را برای دوستان بفرستید</span><span class="arf-howto-num">1</span></div>
                    <div class="arf-howto-row"><span class="arf-howto-txt">دوستان شما در آراد اکسچنج ثبت‌نام کنند</span><span class="arf-howto-num">2</span></div>
                    <div class="arf-howto-row"><span class="arf-howto-txt">با فعالیت آن‌ها، شما درآمد و هدیه دریافت می‌کنید</span><span class="arf-howto-num">3</span></div>
                </div>
            </div>

            <!-- ---------- تب آمار ---------- -->
            <div class="arf-pane" id="arfPaneStats">
                <div class="arf-stats-grid">
                    <div class="arf-stat">
                        <div class="arf-stat-ic"><i class="fas fa-coins"></i></div>
                        <div class="arf-stat-v" id="arfStatEarned"><?php echo number_format($__refStats['total_earned'], 2); ?>€</div>
                        <div class="arf-stat-l">کل درآمد شما</div>
                    </div>
                    <div class="arf-stat">
                        <div class="arf-stat-ic"><i class="fas fa-users"></i></div>
                        <div class="arf-stat-v" id="arfStatCount"><?php echo (int)$__refStats['total_referred']; ?></div>
                        <div class="arf-stat-l">نفر دعوت‌شده</div>
                    </div>
                    <div class="arf-stat">
                        <div class="arf-stat-ic"><i class="fas fa-percentage"></i></div>
                        <div class="arf-stat-v"><?php echo number_format($__refSettings['commission_per_tx_eur'], 2); ?>€</div>
                        <div class="arf-stat-l">پورسانت هر تراکنش</div>
                    </div>
                    <div class="arf-stat">
                        <div class="arf-stat-ic"><i class="fas fa-gift"></i></div>
                        <div class="arf-stat-v"><?php echo number_format($__refSettings['welcome_bonus_eur'], 2); ?>€</div>
                        <div class="arf-stat-l">هدیه‌ی هر دعوت</div>
                    </div>
                </div>

                <div class="arf-chart-card">
                    <div class="arf-chart-head">
                        <span class="arf-chart-title">درآمد این ماه</span>
                    </div>
                    <div class="arf-chart-val">
                        <span id="arfMonthValue">0.00€</span>
                        <span class="arf-chart-pct" id="arfMonthPct"><i class="fas fa-arrow-up"></i> 0%</span>
                    </div>
                    <div class="arf-chart-wrap">
                        <canvas id="arfChart" height="130"></canvas>
                    </div>
                </div>

                <button type="button" class="arf-settle-btn" onclick="arfOpenSettleModal()">
                    <i class="fas fa-wallet"></i> درخواست تسویه کیف‌پول
                </button>
            </div>
        </div>
    </div>

    <!-- مودال درخواست تسویه -->
    <div class="arf-modal-overlay" id="arfSettleModal">
        <div class="arf-modal">
            <div class="arf-modal-head">
                <span><i class="fas fa-hand-holding-usd"></i> درخواست تسویه کیف‌پول یورویی</span>
                <button type="button" class="arf-modal-close" onclick="arfCloseSettleModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="arf-modal-body">
                <div class="arf-modal-balance">موجودی فعلی کیف‌پول: <b id="arfModalBalance">0.00€</b></div>
                <div class="arf-modal-note" id="arfModalMinNote">حداقل مبلغ تسویه ۵ یورو است.</div>

                <label class="arf-field-label">حساب مقصد</label>
                <select id="arfBenSelect" class="arf-select">
                    <option value="">در حال بارگذاری حساب‌ها...</option>
                </select>
                <button type="button" class="arf-add-ben-btn" onclick="arfOpenAddBenInline()"><i class="fas fa-plus-circle"></i> معرفی حساب جدید</button>

                <div id="arfAddBenBox" style="display:none;">
                    <label class="arf-field-label">نام صاحب حساب</label>
                    <input type="text" id="arfBenName" class="arf-input" placeholder="نام و نام‌خانوادگی">
                    <label class="arf-field-label">شماره کارت / شبا</label>
                    <input type="text" id="arfBenCard" class="arf-input" placeholder="شماره کارت یا IBAN">
                    <label class="arf-field-label">نام بانک</label>
                    <input type="text" id="arfBenBank" class="arf-input" placeholder="نام بانک (اختیاری)">
                    <button type="button" class="arf-save-ben-btn" onclick="arfSaveNewBen()">ذخیره حساب</button>
                </div>

                <label class="arf-field-label">مبلغ تسویه (یورو)</label>
                <input type="number" id="arfSettleAmount" class="arf-input" placeholder="حداقل ۵ یورو" min="5" step="0.01">

                <div class="arf-modal-error" id="arfModalError"></div>

                <button type="button" class="arf-submit-btn" onclick="arfSubmitSettle()">
                    <i class="fas fa-paper-plane"></i> ثبت درخواست تسویه
                </button>
            </div>
        </div>
    </div>
    <?php /* پایان بخش زیرمجموعه‌های شما */ ?>
