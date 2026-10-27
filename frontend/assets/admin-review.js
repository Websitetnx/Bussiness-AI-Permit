(function () {
  var select    = document.getElementById('decisionSelect');
  var feeField  = document.getElementById('feeField');
  var feeInput  = feeField ? feeField.querySelector('input[name="assessed_amount"]') : null;
  var submitBtn = document.getElementById('decisionSubmit');
  var labels = {
    'For Review':    'Save decision',
    'Needs Revision':'Request revision',
    'Approved':      'Approve & notify applicant',
    'Released':      'Release permit & issue certificate',
    'Rejected':      'Reject application'
  };
  function update() {
    var val = select.value;
    var isApproved = (val === 'Approved');
    feeField.hidden = !isApproved;
    if (feeInput) feeInput.disabled = !isApproved;
    submitBtn.textContent = labels[val] || 'Save decision';
  }
  select.addEventListener('change', update);
  update();
})();
