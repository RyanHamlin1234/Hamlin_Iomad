M.local_report_completion2 = {};

M.local_report_completion2.init = function(Y) {

    Y.on('change', function(e) {
        Y.one('#mform1').submit();
    }, '#id_company' );

    Y.on('change', function(e) {
        Y.one('#mform1').submit();
    }, '#id_repcourse' );

};
