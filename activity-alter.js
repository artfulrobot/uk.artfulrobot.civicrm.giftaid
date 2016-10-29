/**
 * This function will be executed after the activity is loaded
 * and perform all the changes necessary
 */
CRM.$(function() {

  // Inspect the current Subject field.
  var origSubject = CRM.$('form#Activity input[name="subject"][type="text"]');
  if (origSubject.length ==0) {
    // Oh, it's no there.
    return;
  }
  var eligibility = origSubject.val();
  if (eligibility == '') {
    // New activities need a default.
    eligibility = 'Eligible';
  }
  else if (!eligibility.match(/^(Ine|E)ligible$/)) {
    // Hmmm. This activity does not have a valid subject.
    // Let's leave it as it is and ask the user to fix it. Who knows, maybe
    // they have some greater wisdom...
    alert("This declaration won't work - the subject must be simply Eligible or Ineligible. Please correct this.");
    return;
  }

  // Replace the Subject text input with a Select.
  origSubject.after(
    CRM.$('<select id="subject" name="subject">'
      + '<option value="Eligible">Eligible for Gift Aid</option>'
      + '<option value="Ineligible">Ineligible for Gift Aid</option>'
      + '</select>'
    ).val(eligibility)
  ).remove();
  CRM.$('label[for="subject"]').text("Declaration");

});
