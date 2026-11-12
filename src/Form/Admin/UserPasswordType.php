<?php

namespace App\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The password reset form — the administrator sets a new password.
 */
class UserPasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', TextType::class, [
            'label' => 'New password',
            'attr' => [
                'class' => 'form__control',
                'autocomplete' => 'new-password',
                'data-controller' => 'password',
            ],
            'constraints' => [
                new Assert\NotBlank(message: 'The password is required.'),
                new Assert\Length(min: 8, minMessage: 'The password must be at least 8 characters long.'),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'admin_user_password';
    }
}
